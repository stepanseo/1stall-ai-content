"""
ai_desc_gui.py

GUI-обёртка для удалённого запуска ai_generate_descriptions.php на сервере
через SSH. Сама генерация выполняется на сервере (там же, где 1С-Битрикс
и база данных) — приложение подключается по SSH, запускает задачу В ФОНЕ
на сервере (независимо от SSH-соединения), и периодически проверяет файл
лога и статус процесса короткими запросами.

Такая схема выбрана специально: длинный прогон (сотни товаров, десятки
минут) через один непрерывный SSH-канал ненадёжен — соединение может
оборваться из-за сети, и раньше это выглядело как "зависание" или ложный
код завершения, хотя сам процесс на сервере продолжал работать. Теперь
даже если конкретная проверка не пройдёт (временная проблема сети),
приложение просто попробует снова через несколько секунд — сам процесс
генерации на сервере от этого никак не зависит.

Требования для запуска из исходников:
    pip install paramiko

Для сборки в отдельный .exe (на Windows):
    pip install paramiko pyinstaller
    pyinstaller --onefile --noconsole --name "1stall_generator" ai_desc_gui.py

Готовый .exe появится в папке dist/.
"""

import tkinter as tk
from tkinter import ttk, scrolledtext, messagebox, simpledialog
import threading
import queue
import json
import os
import sys
import time
import shlex
import re
import urllib.request
import urllib.error

try:
    import paramiko
except ImportError:
    paramiko = None

if getattr(sys, "frozen", False):
    # Собранный PyInstaller --onefile .exe: __file__ указывает на временную
    # папку распаковки, которая удаляется после закрытия — конфиг там не
    # сохранится между запусками. Берём папку рядом с самим .exe.
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.dirname(os.path.abspath(__file__))

CONFIG_FILE = os.path.join(BASE_DIR, "config.json")

POLL_INTERVAL_SEC = 3

DEFAULT_CONFIG = {
    "host": "",
    "port": 22,
    "username": "web",
    "password": "",
    "remote_path": "/home/web/1stall.ru/www/local/scripts",
    "router_cheap_api_key": "",
    "router_cheap_model": "claude-sonnet-5",
}


def load_config():
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                return {**DEFAULT_CONFIG, **json.load(f)}
        except Exception:
            pass
    return DEFAULT_CONFIG.copy()


def save_config(cfg):
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)


def setup_text_editing_shortcuts(widget):
    """Контекстное меню + горячие клавиши по физическому коду клавиши.
    Нужно из-за особенности Tkinter: стандартные Ctrl+A/C/V/X определяются
    по символу, который печатает клавиша, а не по её физическому
    расположению — при русской раскладке это часто просто не срабатывает.
    Код клавиш ниже (65=A, 67=C, 86=V, 88=X) — это физические коды,
    одинаковые независимо от раскладки."""

    def select_all(event=None):
        widget.tag_add("sel", "1.0", "end")
        return "break"

    def cut(event=None):
        widget.event_generate("<<Cut>>")
        return "break"

    def copy(event=None):
        widget.event_generate("<<Copy>>")
        return "break"

    def paste(event=None):
        widget.event_generate("<<Paste>>")
        return "break"

    def on_key(event):
        if event.state & 0x4:
            if event.keycode == 65:
                return select_all()
            if event.keycode == 67:
                return copy()
            if event.keycode == 86:
                return paste()
            if event.keycode == 88:
                return cut()

    widget.bind("<Key>", on_key)

    menu = tk.Menu(widget, tearoff=0)
    menu.add_command(label="Вырезать", command=cut)
    menu.add_command(label="Копировать", command=copy)
    menu.add_command(label="Вставить", command=paste)
    menu.add_separator()
    menu.add_command(label="Выделить всё", command=select_all)

    def show_menu(event):
        menu.tk_popup(event.x_root, event.y_root)

    widget.bind("<Button-3>", show_menu)


class App:
    def __init__(self, root):
        self.root = root
        self.root.title("1stall — генерация описаний товаров")
        self.root.geometry("980x620")

        if paramiko is None:
            messagebox.showerror(
                "Не хватает библиотеки",
                "Не установлен модуль paramiko.\nВыполни: pip install paramiko",
            )

        self.cfg = load_config()
        self.output_queue = queue.Queue()
        self.ssh_client = None
        self.running = False
        self.stop_requested = False

        self._build_ui()
        self.root.after(100, self._poll_output)
        self.root.protocol("WM_DELETE_WINDOW", self._on_close)

    # ---------------------------------------------------------- UI

    def _build_ui(self):
        conn_frame = ttk.LabelFrame(self.root, text="Подключение к серверу (SSH)")
        conn_frame.pack(fill="x", padx=10, pady=8)

        ttk.Label(conn_frame, text="Хост:").grid(row=0, column=0, sticky="e", padx=4, pady=4)
        self.host_var = tk.StringVar(value=self.cfg["host"])
        host_entry = ttk.Entry(conn_frame, textvariable=self.host_var, width=25)
        host_entry.grid(row=0, column=1, padx=4, pady=4)
        self._add_copy_paste_menu(host_entry)

        ttk.Label(conn_frame, text="Порт:").grid(row=0, column=2, sticky="e", padx=4, pady=4)
        self.port_var = tk.StringVar(value=str(self.cfg["port"]))
        port_entry = ttk.Entry(conn_frame, textvariable=self.port_var, width=6)
        port_entry.grid(row=0, column=3, padx=4, pady=4)
        self._add_copy_paste_menu(port_entry)

        ttk.Label(conn_frame, text="Логин:").grid(row=1, column=0, sticky="e", padx=4, pady=4)
        self.user_var = tk.StringVar(value=self.cfg["username"])
        user_entry = ttk.Entry(conn_frame, textvariable=self.user_var, width=25)
        user_entry.grid(row=1, column=1, padx=4, pady=4)
        self._add_copy_paste_menu(user_entry)

        ttk.Label(conn_frame, text="Пароль:").grid(row=1, column=2, sticky="e", padx=4, pady=4)
        self.pass_var = tk.StringVar(value=self.cfg["password"])
        pass_entry = ttk.Entry(conn_frame, textvariable=self.pass_var, width=20, show="*")
        pass_entry.grid(row=1, column=3, padx=4, pady=4)
        self._add_copy_paste_menu(pass_entry)

        ttk.Label(conn_frame, text="Путь к скрипту на сервере:").grid(row=2, column=0, sticky="e", padx=4, pady=4)
        self.path_var = tk.StringVar(value=self.cfg["remote_path"])
        path_entry = ttk.Entry(conn_frame, textvariable=self.path_var, width=55)
        path_entry.grid(row=2, column=1, columnspan=3, sticky="w", padx=4, pady=4)
        self._add_copy_paste_menu(path_entry)

        ttk.Label(conn_frame, text="Ключ API (router.cheap, sk-...):").grid(row=3, column=0, sticky="e", padx=4, pady=4)
        self.api_key_var = tk.StringVar(value=self.cfg["router_cheap_api_key"])
        # Поле НЕ маскируется (без show="*"), чтобы ключ можно было увидеть и
        # свериться глазами - раньше это уже спасало от ошибки (случайно был
        # вставлен не тот текст вместо реального ключа). Плюс отдельное
        # контекстное меню правой кнопкой - на всякий случай, если обычные
        # Ctrl+C/Ctrl+V/Ctrl+X через системный буфер обмена почему-то не
        # сработают в собранном .exe.
        api_key_entry = ttk.Entry(conn_frame, textvariable=self.api_key_var, width=45)
        api_key_entry.grid(row=3, column=1, columnspan=2, sticky="w", padx=4, pady=4)
        self._add_copy_paste_menu(api_key_entry)

        self.balance_btn = ttk.Button(
            conn_frame, text="Проверить баланс", command=self._check_router_balance
        )
        self.balance_btn.grid(row=3, column=3, sticky="w", padx=4, pady=4)

        ttk.Label(conn_frame, text="Модель:").grid(row=4, column=0, sticky="e", padx=4, pady=4)
        self.model_var = tk.StringVar(value=self.cfg["router_cheap_model"])
        model_entry = ttk.Entry(conn_frame, textvariable=self.model_var, width=25)
        model_entry.grid(row=4, column=1, sticky="w", padx=4, pady=4)
        self._add_copy_paste_menu(model_entry)

        ttk.Button(conn_frame, text="Сохранить настройки подключения", command=self._save_settings).grid(
            row=5, column=0, columnspan=4, pady=6
        )

        run_frame = ttk.LabelFrame(self.root, text="Запуск генерации")
        run_frame.pack(fill="x", padx=10, pady=8)

        ttk.Label(run_frame, text="ID раздела:").grid(row=0, column=0, sticky="e", padx=4, pady=4)
        self.section_var = tk.StringVar()
        ttk.Entry(run_frame, textvariable=self.section_var, width=12).grid(row=0, column=1, padx=4, pady=4)

        ttk.Label(run_frame, text="Лимит товаров (необязательно):").grid(row=0, column=2, sticky="e", padx=4, pady=4)
        self.limit_var = tk.StringVar()
        ttk.Entry(run_frame, textvariable=self.limit_var, width=8).grid(row=0, column=3, padx=4, pady=4)

        run_notebook = ttk.Notebook(run_frame)
        run_notebook.grid(row=1, column=0, columnspan=4, sticky="we", padx=4, pady=(4, 8))

        # ---- Вкладка 1: Тексты товарам ----
        product_tab = ttk.Frame(run_notebook)
        run_notebook.add(product_tab, text="📦 Тексты товарам")

        btn_frame = ttk.Frame(product_tab)
        btn_frame.pack(pady=8)

        self.review_btn = ttk.Button(btn_frame, text="Сгенерировать описания", command=self._start_review)
        self.review_btn.pack(side="left", padx=4)

        self.apply_btn = ttk.Button(btn_frame, text="Записать в Битрикс (apply)", command=self._start_apply)
        self.apply_btn.pack(side="left", padx=4)

        self.stop_btn = ttk.Button(btn_frame, text="Прекратить слежение", command=self._stop, state="disabled")
        self.stop_btn.pack(side="left", padx=4)

        self.check_btn = ttk.Button(btn_frame, text="Проверить кол-во сгенерированных", command=self._check_count)
        self.check_btn.pack(side="left", padx=4)

        self.view_report_btn = ttk.Button(btn_frame, text="Просмотр сгенерированных текстов", command=self._open_report_viewer)
        self.view_report_btn.pack(side="left", padx=4)

        btn_frame2 = ttk.Frame(product_tab)
        btn_frame2.pack(pady=(0, 8))

        self.ready_ids_btn = ttk.Button(
            btn_frame2, text="Просмотр ID с готовыми текстами",
            command=lambda: self._open_ready_ids_window("products"),
        )
        self.ready_ids_btn.pack(side="left", padx=4)

        self.indexnow_btn = ttk.Button(btn_frame2, text="Отправить в IndexNow", command=self._start_indexnow)
        self.indexnow_btn.pack(side="left", padx=4)

        self.edit_prompt_btn = ttk.Button(btn_frame2, text="Редактировать промпт", command=self._open_prompt_editor)
        self.edit_prompt_btn.pack(side="left", padx=4)

        self.delete_report_btn = ttk.Button(
            btn_frame2, text="Удалить текущие тексты (для полной перегенерации)",
            command=self._delete_report,
        )
        self.delete_report_btn.pack(side="left", padx=4)

        self.prompt_tester_btn = ttk.Button(
            btn_frame2, text="Тестирование промпта",
            command=self._open_prompt_tester,
        )
        self.prompt_tester_btn.pack(side="left", padx=4)

        # ---- Вкладка 2: Генерация SEO-страниц ----
        seo_tab = ttk.Frame(run_notebook)
        run_notebook.add(seo_tab, text="🔍 Генерация SEO-страниц")

        btn_frame3 = ttk.Frame(seo_tab)
        btn_frame3.pack(pady=8)

        self.smartfilter_gen_btn = ttk.Button(
            btn_frame3, text="Генерация по списку ссылок (SEO-фильтры)",
            command=self._open_smartfilter_window,
        )
        self.smartfilter_gen_btn.pack(side="left", padx=4)

        self.smartfilter_apply_btn = ttk.Button(
            btn_frame3, text="Применить тексты умного фильтра",
            command=self._apply_smartfilter,
        )
        self.smartfilter_apply_btn.pack(side="left", padx=4)

        self.smartfilter_delete_report_btn = ttk.Button(
            btn_frame3, text="Удалить тексты умного фильтра (для полной перегенерации)",
            command=self._delete_smartfilter_report,
        )
        self.smartfilter_delete_report_btn.pack(side="left", padx=4)

        btn_frame4 = ttk.Frame(seo_tab)
        btn_frame4.pack(pady=(0, 8))

        self.smartfilter_ready_ids_btn = ttk.Button(
            btn_frame4, text="Просмотр ID SEO-страниц с готовыми текстами",
            command=lambda: self._open_ready_ids_window("smartfilter"),
        )
        self.smartfilter_ready_ids_btn.pack(side="left", padx=4)

        self.seo_edit_prompt_btn = ttk.Button(btn_frame4, text="Редактировать промпт", command=self._open_prompt_editor)
        self.seo_edit_prompt_btn.pack(side="left", padx=4)

        self.seo_prompt_tester_btn = ttk.Button(
            btn_frame4, text="Тестирование промпта",
            command=self._open_prompt_tester,
        )
        self.seo_prompt_tester_btn.pack(side="left", padx=4)

        status_frame = ttk.Frame(self.root)
        status_frame.pack(fill="x", padx=12)
        ttk.Label(status_frame, text="Статус:").pack(side="left")
        self.status_var = tk.StringVar(value="Готово")
        ttk.Label(status_frame, textvariable=self.status_var, foreground="blue").pack(side="left", padx=6)

        log_frame = ttk.LabelFrame(self.root, text="Лог выполнения")
        log_frame.pack(fill="both", expand=True, padx=10, pady=8)

        self.log_text = scrolledtext.ScrolledText(
            log_frame, wrap="word", state="disabled", bg="black", fg="#00ff66", font=("Consolas", 9)
        )
        self.log_text.pack(fill="both", expand=True, padx=4, pady=4)

    # ---------------------------------------------------------- Настройки

    def _save_settings(self):
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return

        self.cfg = {
            "host": self.host_var.get().strip(),
            "port": port,
            "username": self.user_var.get().strip(),
            "password": self.pass_var.get(),
            "remote_path": self.path_var.get().strip(),
            "router_cheap_api_key": self.api_key_var.get().strip(),
            "router_cheap_model": self.model_var.get().strip(),
        }
        save_config(self.cfg)
        messagebox.showinfo(
            "Готово",
            "Настройки подключения сохранены.\n(логин/пароль хранятся в config.json рядом с приложением)",
        )

    # ---------------------------------------------------------- Лог/вывод

    def _append_log(self, text):
        self.log_text.configure(state="normal")
        self.log_text.insert("end", text)
        self.log_text.see("end")
        self.log_text.configure(state="disabled")

    def _poll_output(self):
        try:
            while True:
                line = self.output_queue.get_nowait()
                if line is None:
                    self._finish_run()
                else:
                    self._append_log(line)
        except queue.Empty:
            pass
        self.root.after(100, self._poll_output)

    def _finish_run(self):
        self.running = False
        self.stop_requested = False
        self.review_btn.configure(state="normal")
        self.apply_btn.configure(state="normal")
        self.indexnow_btn.configure(state="normal")
        self.stop_btn.configure(state="disabled")
        self.status_var.set("Готово")

    # ---------------------------------------------------------- Запуск задач

    def _start_review(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return
        limit = self.limit_var.get().strip()
        inner_cmd = f"php ai_generate_descriptions.php review {section}"
        if limit:
            inner_cmd += f" {limit}"
        self._start_remote_job("review", section, inner_cmd)

    def _start_apply(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return
        inner_cmd = f"php ai_generate_descriptions.php apply {section}"
        self._start_remote_job("apply", section, inner_cmd)

    def _start_remote_job(self, job_type, section, inner_cmd):
        if paramiko is None:
            messagebox.showerror("Ошибка", "Не установлен модуль paramiko (pip install paramiko).")
            return
        if self.running:
            messagebox.showwarning("Внимание", "Уже выполняется другая задача, дождись завершения.")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        self.running = True
        self.stop_requested = False
        self.review_btn.configure(state="disabled")
        self.apply_btn.configure(state="disabled")
        self.indexnow_btn.configure(state="disabled")
        self.stop_btn.configure(state="normal")
        self.status_var.set("Запускаю на сервере...")
        self._append_log(f"\n$ (в фоне на сервере) {inner_cmd}\n")

        thread = threading.Thread(
            target=self._remote_job_worker,
            args=(
                host, port, user, password, remote_path, job_type, section, inner_cmd,
                self.api_key_var.get().strip(), self.model_var.get().strip(),
            ),
            daemon=True,
        )
        thread.start()

    @staticmethod
    def _connect(host, port, user, password):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=host, port=port, username=user, password=password, timeout=15)
        client.get_transport().set_keepalive(30)
        return client

    def _remote_job_worker(self, host, port, user, password, remote_path, job_type, section, inner_cmd, api_key="", model=""):
        log_name = f"gui_run_{job_type}_{section}.log"
        pid_name = f"gui_run_{job_type}_{section}.pid"
        log_path = f"{remote_path}/{log_name}"
        pid_path = f"{remote_path}/{pid_name}"

        # Ключ и модель передаются как переменные окружения перед командой —
        # безопасно экранируем их на случай спецсимволов (shlex.quote даёт
        # корректную POSIX-shell-экранировку). PHP-скрипт читает их через
        # getenv(), если они заданы — иначе использует значения по умолчанию,
        # зашитые в самом файле на сервере.
        env_parts = []
        if api_key:
            env_parts.append(f"ROUTER_CHEAP_API_KEY={shlex.quote(api_key)}")
        if model:
            env_parts.append(f"ROUTER_CHEAP_MODEL={shlex.quote(model)}")
        env_prefix = (" ".join(env_parts) + " ") if env_parts else ""

        # Запуск в фоне на сервере: вывод команды идёт в файл лога, PID
        # процесса — в отдельный файл. Возврата самой команды запуска ждём
        # быстро — запущенный процесс продолжает работать независимо от
        # того, жив ли ещё этот конкретный SSH-канал. Отдельная вложенная
        # 'sh -c' здесь не нужна — SSH-сервер и так выполняет команду через
        # шелл пользователя, а лишняя вложенность только усложняла бы
        # экранирование ключа с спецсимволами.
        start_cmd = (
            f"cd {remote_path} && rm -f {log_name} {pid_name} && "
            f"{env_prefix}{inner_cmd} > {log_name} 2>&1 & echo $! > {pid_name}"
        )

        client = None
        try:
            client = self._connect(host, port, user, password)
            self.ssh_client = client

            _, stdout, stderr = client.exec_command(start_cmd)
            stdout.channel.recv_exit_status()
            err_text = stderr.read().decode("utf-8", errors="replace").strip()
            if err_text:
                self.output_queue.put(f"[предупреждение при запуске]: {err_text}\n")

            self.output_queue.put("Задача запущена на сервере в фоне. Слежу за логом...\n\n")

            offset = 0

            while True:
                if self.stop_requested:
                    self.output_queue.put(
                        "\n[Слежение остановлено. Процесс на сервере, скорее всего, ещё работает в "
                        "фоне — снова нажми 'Сгенерировать'/'Записать', чтобы продолжить наблюдение.]\n"
                    )
                    break

                try:
                    if client is None or client.get_transport() is None or not client.get_transport().is_active():
                        client = self._connect(host, port, user, password)
                        self.ssh_client = client

                    tail_cmd = f"tail -c +{offset + 1} {log_path} 2>/dev/null"
                    _, out, _ = client.exec_command(tail_cmd)
                    new_data = out.read()
                    if new_data:
                        offset += len(new_data)
                        self.output_queue.put(new_data.decode("utf-8", errors="replace"))

                    status_cmd = (
                        f"kill -0 $(cat {pid_path} 2>/dev/null) 2>/dev/null && echo RUNNING || echo DONE"
                    )
                    _, out2, _ = client.exec_command(status_cmd)
                    status = out2.read().decode("utf-8", errors="replace").strip()

                    if status == "DONE":
                        # Добираем то, что могло появиться в логе между
                        # последней проверкой и фактическим завершением.
                        _, out3, _ = client.exec_command(tail_cmd)
                        tail_more = out3.read()
                        if tail_more:
                            self.output_queue.put(tail_more.decode("utf-8", errors="replace"))
                        self.output_queue.put("\n--- Задача на сервере завершена ---\n")
                        break

                except Exception as poll_err:
                    self.output_queue.put(
                        f"\n[временная проблема связи: {poll_err} — переподключаюсь через "
                        f"{POLL_INTERVAL_SEC} сек]\n"
                    )
                    try:
                        if client:
                            client.close()
                    except Exception:
                        pass
                    client = None
                    self.ssh_client = None

                time.sleep(POLL_INTERVAL_SEC)

        except Exception as e:
            self.output_queue.put(f"\nОШИБКА: {e}\n")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass
            self.ssh_client = None
            self.output_queue.put(None)

    def _open_report_viewer(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        ReportViewerWindow(self, host, port, user, password, remote_path, section)

    def _start_indexnow(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return
        inner_cmd = f"php ai_generate_descriptions.php indexnow {section}"
        self._start_remote_job("indexnow", section, inner_cmd)

    def _check_count(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        self.check_btn.configure(state="disabled")
        thread = threading.Thread(
            target=self._check_count_worker,
            args=(host, port, user, password, remote_path, section),
            daemon=True,
        )
        thread.start()

    def _check_count_worker(self, host, port, user, password, remote_path, section):
        cmd = f"cd {remote_path} && php ai_generate_descriptions.php count {section}"
        client = None
        try:
            client = self._connect(host, port, user, password)
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            result = out.read().decode("utf-8", errors="replace")
            err_text = err.read().decode("utf-8", errors="replace").strip()
            self.output_queue.put(f"\n[Проверка]{result}")
            if err_text:
                self.output_queue.put(f"[Проверка, доп. вывод]: {err_text}\n")
        except Exception as e:
            self.output_queue.put(f"\n[Проверка] ОШИБКА: {e}\n")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass
            self.root.after(0, lambda: self.check_btn.configure(state="normal"))

    def _stop(self):
        self.stop_requested = True
        self._append_log("\n[Останавливаю слежение за логом...]\n")

    def _on_close(self):
        self.stop_requested = True
        if self.ssh_client:
            try:
                self.ssh_client.close()
            except Exception:
                pass
        self.root.destroy()

    # ---------------------------------------------------------- Копирование/вставка в поля

    @staticmethod
    def _add_copy_paste_menu(entry):
        """Контекстное меню правой кнопкой + Ctrl+A/C/V/X по физическому коду
        клавиши (65=A, 67=C, 86=V, 88=X) - не зависит от раскладки клавиатуры,
        та же защита, что и в setup_text_editing_shortcuts() для текстовых
        полей, только адаптированная под ttk.Entry (у Entry нет tag_add -
        выделение делается через select_range)."""
        menu = tk.Menu(entry, tearoff=0)
        menu.add_command(label="Вырезать", command=lambda: entry.event_generate("<<Cut>>"))
        menu.add_command(label="Копировать", command=lambda: entry.event_generate("<<Copy>>"))
        menu.add_command(label="Вставить", command=lambda: entry.event_generate("<<Paste>>"))
        menu.add_separator()
        menu.add_command(label="Выделить всё", command=lambda: entry.select_range(0, "end"))

        def show_menu(event):
            try:
                menu.tk_popup(event.x_root, event.y_root)
            finally:
                menu.grab_release()

        entry.bind("<Button-3>", show_menu)

        def on_key(event):
            if event.state & 0x4:
                if event.keycode == 65:
                    entry.select_range(0, "end")
                    return "break"
                if event.keycode == 67:
                    entry.event_generate("<<Copy>>")
                    return "break"
                if event.keycode == 86:
                    entry.event_generate("<<Paste>>")
                    return "break"
                if event.keycode == 88:
                    entry.event_generate("<<Cut>>")
                    return "break"

        entry.bind("<Key>", on_key)
        return entry

    # ---------------------------------------------------------- Проверка баланса router.cheap

    def _check_router_balance(self):
        api_key = self.api_key_var.get().strip()
        if not api_key:
            messagebox.showerror("Ошибка", "Сначала укажи ключ API.")
            return
        model = self.model_var.get().strip() or "claude-3-5-haiku-20241022"

        self.balance_btn.configure(state="disabled", text="Проверяю...")

        thread = threading.Thread(
            target=self._check_balance_worker, args=(api_key, model), daemon=True
        )
        thread.start()

    def _check_balance_worker(self, api_key, model):
        # Отдельного эндпоинта "баланс" у router.cheap нет (по крайней мере
        # не найден в документации) - поэтому проверяем ключ минимальным
        # запросом (max_tokens=1, короткий текст), который стоит доли цента.
        # Если денег не хватает даже на такой запрос, router.cheap сам
        # возвращает точный остаток в тексте ошибки (как мы уже видели) -
        # его и показываем. Если запрос проходит успешно - ключ рабочий,
        # но точную сумму API в этом случае не сообщает.
        urls = ["https://router.cheap/v1/messages", "https://direct.router-cheap.com/v1/messages"]
        payload = json.dumps({
            "model": model,
            "max_tokens": 1,
            "messages": [{"role": "user", "content": "."}],
        }).encode("utf-8")
        headers = {
            "Content-Type": "application/json",
            "x-api-key": api_key,
            "anthropic-version": "2023-06-01",
        }

        last_error = None
        for url in urls:
            req = urllib.request.Request(url, data=payload, method="POST", headers=headers)
            try:
                with urllib.request.urlopen(req, timeout=20) as resp:
                    resp.read()
                self.root.after(0, lambda: self._show_balance_result(
                    "Ключ рабочий - пробный запрос (1 токен) прошёл успешно.\n\n"
                    "Точную сумму баланса API не сообщает при успешном запросе - "
                    "она видна только в момент отказа по нехватке денег, либо в "
                    "личном кабинете router.cheap.",
                    is_error=False,
                ))
                return
            except urllib.error.HTTPError as e:
                body = e.read().decode("utf-8", errors="replace")
                try:
                    data = json.loads(body)
                    msg = data.get("error", {}).get("message", body)
                except Exception:
                    msg = body
                last_error = f"HTTP {e.code}: {msg}"
                # 401/403 - это ответ САМОГО router.cheap про ключ/баланс,
                # оба адреса ведут на один и тот же кошелёк, второй пробовать
                # бессмысленно - сразу показываем результат.
                if e.code in (401, 403):
                    self.root.after(0, lambda m=last_error: self._show_balance_result(m, is_error=True))
                    return
                continue
            except Exception as e:
                last_error = str(e)
                continue

        self.root.after(0, lambda: self._show_balance_result(
            f"Не удалось проверить ключ: {last_error}", is_error=True
        ))

    def _show_balance_result(self, text, is_error=False):
        self.balance_btn.configure(state="normal", text="Проверить баланс")
        if is_error:
            messagebox.showwarning("Результат проверки", text)
        else:
            messagebox.showinfo("Результат проверки", text)

    # ---------------------------------------------------------- Редактор промптов

    @staticmethod
    def _parse_section_ids(raw):
        # Тот же разбор, что уже используется в окне промптов: разделители —
        # запятая, точка с запятой или пробел, в любом сочетании.
        raw_parts = raw.replace(";", ",").replace(" ", ",").split(",")
        return [p.strip() for p in raw_parts if p.strip()]

    def _delete_report(self):
        section_ids = self._parse_section_ids(self.section_var.get().strip())
        if not section_ids:
            messagebox.showerror("Ошибка", "Укажи ID раздела (можно несколько через запятую).")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        sections_label = ", ".join(section_ids)
        if not messagebox.askyesno(
            "Подтверждение",
            f"Удалить файлы отчёта для раздела(-ов) {sections_label} на сервере?\n\n"
            "Это НЕ трогает уже записанные в Битрикс тексты (DETAIL_TEXT товаров), "
            "но сотрёт локальную историю генерации — при следующем запуске "
            "'Сгенерировать описания' все товары этих разделов будут обработаны заново "
            "и уже сгенерированные тексты будут перезаписаны новыми.\n\n"
            "Продолжить?",
        ):
            return

        client = None
        try:
            client = self._connect(host, port, user, password)
            report_files = " ".join(
                f"{remote_path}/ai_desc_report_section_{sid}.json" for sid in section_ids
            )
            cmd = f"rm -f {report_files}"
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            err_text = err.read().decode("utf-8", errors="replace").strip()
            if err_text:
                messagebox.showerror("Ошибка", f"Не удалось удалить файлы: {err_text}")
            else:
                messagebox.showinfo(
                    "Готово",
                    f"Файлы отчёта для раздела(-ов) {sections_label} удалены.\n"
                    "Следующий запуск 'Сгенерировать описания' начнёт эти разделы с нуля.",
                )
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось подключиться: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _delete_smartfilter_report(self):
        section_ids = self._parse_section_ids(self.section_var.get().strip())
        if not section_ids:
            messagebox.showerror("Ошибка", "Укажи ID раздела (можно несколько через запятую).")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        sections_label = ", ".join(section_ids)
        if not messagebox.askyesno(
            "Подтверждение",
            f"Удалить файлы отчёта умного фильтра для раздела(-ов) {sections_label} на сервере?\n\n"
            "Это НЕ трогает уже применённые страницы (элементы в инфоблоке Ewp: CEO Чпу), "
            "но сотрёт локальную историю генерации — при следующем запуске "
            "'Генерация по списку ссылок' все ссылки этих разделов будут обработаны заново "
            "и уже сгенерированные тексты будут перезаписаны новыми.\n\n"
            "Продолжить?",
        ):
            return

        client = None
        try:
            client = self._connect(host, port, user, password)
            report_files = " ".join(
                f"{remote_path}/ai_desc_smartfilter_report_section_{sid}.json" for sid in section_ids
            )
            cmd = f"rm -f {report_files}"
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            err_text = err.read().decode("utf-8", errors="replace").strip()
            if err_text:
                messagebox.showerror("Ошибка", f"Не удалось удалить файлы: {err_text}")
            else:
                messagebox.showinfo(
                    "Готово",
                    f"Файлы отчёта умного фильтра для раздела(-ов) {sections_label} удалены.\n"
                    "Следующий запуск генерации начнёт эти разделы с нуля.",
                )
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось подключиться: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _open_smartfilter_window(self):
        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        SmartFilterWindow(self, host, port, user, password, remote_path)

    def _apply_smartfilter(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела (того, для которого применяем тексты умного фильтра).")
            return
        inner_cmd = f"php ai_generate_descriptions.php applysmartfilter {section}"
        self._start_remote_job("smartfilter_apply", section, inner_cmd)

    def _open_prompt_tester(self):
        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        PromptTesterWindow(self, host, port, user, password, remote_path)

    def _open_prompt_editor(self):
        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        section = self.section_var.get().strip()
        PromptEditorWindow(self, host, port, user, password, remote_path, section)

    def _open_ready_ids_window(self, kind):
        """kind: "products" — список РАЗДЕЛОВ (ID и название), для которых
        на сервере уже есть готовые тексты товаров — не привязан к полю
        "ID раздела", сканирует все отчёты сразу. "smartfilter" — список
        адресов SEO-страниц умного фильтра с готовым текстом (и их
        заголовков) для раздела из поля "ID раздела"."""
        section = self.section_var.get().strip()
        if kind == "smartfilter" and not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        ReadyIdsWindow(self, host, port, user, password, remote_path, section, kind)


class ReadyIdsWindow(tk.Toplevel):
    """Отдельное окно со списком того, для чего уже есть готовый
    сгенерированный текст (запись в отчёте на сервере со статусом
    'approved' или 'applied'). Для "products" — список РАЗДЕЛОВ (не
    товаров) в формате "ID - название раздела", отсортированный по
    возрастанию ID: показывает, в каких разделах уже есть готовые тексты
    товаров, без перечисления самих товаров. Для "smartfilter" — список
    адресов SEO-страниц конкретного раздела в формате "адрес - заголовок",
    отсортированный по алфавиту адреса (у SEO-страниц умного фильтра нет
    числового ID — это виртуальные страницы, не элементы Битрикса)."""

    def __init__(self, app, host, port, user, password, remote_path, section, kind):
        super().__init__(app.root)
        self.app = app
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.remote_path = remote_path
        self.section = section
        self.kind = kind  # "products" или "smartfilter"

        if kind == "products":
            self.title("Разделы с готовыми текстами товаров")
            label_text = "Все разделы с готовыми текстами товаров, отсортировано по возрастанию ID:"
        else:
            self.title(f"SEO-страницы с готовым текстом — раздел {section}")
            label_text = f"Раздел {section}, отсортировано по алфавиту адреса:"
        self.geometry("480x560")

        ttk.Label(self, text=label_text, foreground="#555555").pack(anchor="w", padx=10, pady=(10, 4))

        list_frame = ttk.Frame(self)
        list_frame.pack(fill="both", expand=True, padx=10, pady=(0, 8))

        scrollbar = ttk.Scrollbar(list_frame)
        scrollbar.pack(side="right", fill="y")

        self.listbox = tk.Listbox(list_frame, font=("Consolas", 10), yscrollcommand=scrollbar.set)
        self.listbox.pack(side="left", fill="both", expand=True)
        scrollbar.config(command=self.listbox.yview)

        self.status_var = tk.StringVar(value="Загружаю...")
        ttk.Label(self, textvariable=self.status_var, foreground="blue").pack(anchor="w", padx=10, pady=(0, 10))

        self._load()

    def _connect(self):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=self.host, port=self.port, username=self.user, password=self.password, timeout=15)
        return client

    def _load(self):
        thread = threading.Thread(target=self._load_worker, daemon=True)
        thread.start()

    def _load_worker(self):
        if self.kind == "products":
            # Список разделов не привязан к конкретному "ID раздела" —
            # сканирует все отчёты на сервере, аргумент не нужен.
            cmd = f"cd {self.remote_path} && php ai_generate_descriptions.php listreadyids"
        else:
            cmd = f"cd {self.remote_path} && php ai_generate_descriptions.php listreadysmartfilterids {self.section}"
        client = None
        try:
            client = self._connect()
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            result = out.read().decode("utf-8", errors="replace")
            err_text = err.read().decode("utf-8", errors="replace").strip()

            lines = [line.strip() for line in result.splitlines() if line.strip()]
            # Первая строка от PHP — короткая сводка ("Раздел N: готовых
            # текстов — X"), остальные — уже готовые строки вида "ID - название".
            summary = lines[0] if lines else ""
            items = lines[1:] if len(lines) > 1 else []

            # Сортируем ещё раз и на стороне GUI (хотя PHP уже отсортировал) —
            # просто на случай, чтобы не зависеть от порядка вывода сервера.
            if self.kind == "products":
                def sort_key(line):
                    # Строка вида "384 - Лента бронзовая" — сортируем по
                    # числу перед " - ", а не по алфавиту всей строки.
                    head = line.split(" - ", 1)[0].strip()
                    try:
                        return (0, int(head))
                    except ValueError:
                        return (1, line)
                items = sorted(items, key=sort_key)
            else:
                items = sorted(items)

            self.after(0, lambda: self._show_result(summary, items, err_text))
        except Exception as e:
            self.after(0, lambda err=e: self.status_var.set(f"Ошибка: {err}"))
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _show_result(self, summary, items, err_text):
        self.listbox.delete(0, "end")
        for item in items:
            self.listbox.insert("end", item)
        if err_text:
            self.status_var.set(f"{summary}  [предупреждение: {err_text}]")
        else:
            self.status_var.set(summary or f"Найдено: {len(items)}")


class PromptEditorTab(ttk.Frame):
    """Один "экран" редактора промптов — либо товарные промпты, либо
    промпты SEO-страниц умного фильтра. Логика идентична для обоих типов,
    отличаются только используемые команды скрипта и папка на сервере —
    поэтому вынесено в один переиспользуемый класс с параметрами."""

    def __init__(self, parent, app, host, port, user, password, remote_path,
                 remote_dir, list_cmd, which_cmd, bind_cmd, hint_text, section=None):
        super().__init__(parent)
        self.app = app
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.remote_path = remote_path
        self.remote_dir = remote_dir  # полный путь на сервере к папке с файлами этого типа
        self.list_cmd = list_cmd
        self.which_cmd = which_cmd
        self.bind_cmd = bind_cmd

        top_frame = ttk.Frame(self)
        top_frame.pack(fill="x", padx=10, pady=8)

        ttk.Label(top_frame, text="Файл промпта:").pack(side="left")
        self.file_var = tk.StringVar()
        self.file_combo = ttk.Combobox(top_frame, textvariable=self.file_var, width=40, state="readonly")
        self.file_combo.pack(side="left", padx=6)
        self.file_combo.bind("<<ComboboxSelected>>", lambda e: self._load_selected())

        ttk.Button(top_frame, text="Создать новый файл", command=self._create_new_file).pack(side="left", padx=4)
        ttk.Button(top_frame, text="Привязать к разделу", command=self._bind_current_to_section).pack(side="left", padx=4)

        self.save_btn = ttk.Button(top_frame, text="Сохранить на сервер", command=self._save_current)
        self.save_btn.pack(side="right", padx=4)

        self.info_var = tk.StringVar(value="")
        ttk.Label(self, textvariable=self.info_var, foreground="blue").pack(anchor="w", padx=12)

        self.text_area = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 10))
        self.text_area.pack(fill="both", expand=True, padx=10, pady=8)
        setup_text_editing_shortcuts(self.text_area)

        self.current_file = None

        ttk.Label(self, text=hint_text, wraplength=760, foreground="#555555").pack(anchor="w", padx=12, pady=(0, 8))

        self._refresh_file_list(preselect_for_section=section)

    def _connect(self):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=self.host, port=self.port, username=self.user, password=self.password, timeout=15)
        # keepalive — некоторые роутеры/файрволы разрывают "неактивные" TCP-
        # соединения, если не видят в них трафика; keepalive шлёт пустые
        # пакеты каждые 30 секунд, чтобы соединение не считалось неактивным.
        transport = client.get_transport()
        if transport:
            transport.set_keepalive(30)
        return client

    def _dir_label(self):
        return self.remote_dir.replace(self.remote_path + "/", "")

    def _refresh_file_list(self, preselect_for_section=None):
        client = None
        try:
            client = self._connect()

            cmd = f"cd {self.remote_path} && php ai_generate_descriptions.php {self.list_cmd}"
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            files = [line.strip() for line in out.read().decode("utf-8", errors="replace").splitlines() if line.strip()]

            if not files:
                messagebox.showwarning("Пусто", f"Не найдено файлов промптов в папке {self._dir_label()}/ на сервере.")
                return

            self.file_combo["values"] = files

            selected = files[0]
            if preselect_for_section:
                cmd2 = f"cd {self.remote_path} && php ai_generate_descriptions.php {self.which_cmd} {preselect_for_section}"
                _, out2, _ = client.exec_command(cmd2)
                out2.channel.recv_exit_status()
                resolved = out2.read().decode("utf-8", errors="replace").strip()
                if resolved in files:
                    selected = resolved

            self.file_var.set(selected)
            self._load_selected()

        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось получить список файлов: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _bind_current_to_section(self):
        filename = self.file_var.get().strip()
        if not filename:
            messagebox.showerror("Ошибка", "Сначала выбери файл промпта из списка.")
            return

        default_section = self.app.section_var.get().strip()
        section_ids_raw = simpledialog.askstring(
            "Привязка к разделу(-ам)",
            f"К каким ID разделов привязать файл {self._dir_label()}/{filename}?\n"
            "Можно указать несколько через запятую, например: 720, 721, 722",
            initialvalue=default_section,
            parent=self,
        )
        if not section_ids_raw or not section_ids_raw.strip():
            return

        raw_parts = section_ids_raw.replace(";", ",").replace(" ", ",").split(",")
        section_ids = [p.strip() for p in raw_parts if p.strip()]
        if not section_ids:
            return

        client = None
        try:
            client = self._connect()
            results = []
            for section_id in section_ids:
                cmd = f"cd {self.remote_path} && php ai_generate_descriptions.php {self.bind_cmd} {section_id} {filename}"
                _, out, err = client.exec_command(cmd)
                out.channel.recv_exit_status()
                result = out.read().decode("utf-8", errors="replace").strip()
                results.append(result or f"Раздел {section_id}: привязка обновлена.")
            messagebox.showinfo("Готово", "\n".join(results))
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось привязать: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _create_new_file(self):
        filename = simpledialog.askstring(
            "Новый файл промпта",
            "Имя файла (например: pipes.txt):",
            parent=self,
        )
        if not filename:
            return

        filename = filename.strip()
        if not filename.endswith(".txt"):
            filename += ".txt"

        existing = list(self.file_combo["values"])
        if filename in existing:
            messagebox.showerror("Ошибка", f"Файл {filename} уже существует. Выбери его из списка для редактирования.")
            return

        starter_template = (
            "Ты — SEO-копирайтер металлоторговой компании.\n\n"
            "Напиши уникальный текст на основе данных ниже.\n\n"
            "Название: {{NAME}}\n"
            "Категория: {{SECTION}}\n"
            "Характеристики:\n"
            "{{PROPS}}\n"
            "{{EXTRA_BLOCK}}{{REWRITE_BLOCK}}\n"
            "Требования к тексту:\n"
            "1. HTML-теги <h2>/<h3>/<p> (без markdown).\n"
            "2. Не выдумывай характеристики, которых нет в списке выше.\n"
            "3. Без рекламных клише.\n"
        )

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_dir}/{filename}"
            with sftp.open(remote_file, "w") as f:
                f.write(starter_template.encode("utf-8"))
            sftp.close()

            default_section = self.app.section_var.get().strip()
            section_ids_raw = simpledialog.askstring(
                "Привязка к разделу(-ам)",
                "К каким ID разделов привязать этот промпт?\n"
                "Можно указать несколько через запятую, например: 720, 721, 722\n"
                "(оставь пустым, если хочешь привязать позже вручную)",
                initialvalue=default_section,
                parent=self,
            )

            bind_message = ""
            if section_ids_raw and section_ids_raw.strip():
                raw_parts = section_ids_raw.replace(";", ",").replace(" ", ",").split(",")
                section_ids = [p.strip() for p in raw_parts if p.strip()]
                bind_results = []
                for section_id in section_ids:
                    bind_cmd = f"cd {self.remote_path} && php ai_generate_descriptions.php {self.bind_cmd} {section_id} {filename}"
                    _, bind_out, bind_err = client.exec_command(bind_cmd)
                    bind_out.channel.recv_exit_status()
                    bind_result = bind_out.read().decode("utf-8", errors="replace").strip()
                    bind_results.append(bind_result or f"Раздел {section_id}: привязка обновлена.")
                bind_message = "\n\n" + "\n".join(bind_results)

            messagebox.showinfo(
                "Готово",
                f"Файл {self._dir_label()}/{filename} создан на сервере.{bind_message}",
            )
            self._refresh_file_list()
            self.file_var.set(filename)
            self._load_selected()
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось создать файл: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _load_selected(self):
        filename = self.file_var.get().strip()
        if not filename:
            return

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_dir}/{filename}"
            with sftp.open(remote_file, "r") as f:
                content = f.read().decode("utf-8", errors="replace")
            sftp.close()

            self.text_area.delete("1.0", "end")
            self.text_area.insert("1.0", content)
            self.current_file = filename
            self.info_var.set(f"Загружено: {self._dir_label()}/{filename}")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось загрузить файл: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _save_current(self):
        filename = self.file_var.get().strip()
        if not filename:
            messagebox.showerror("Ошибка", "Файл не выбран.")
            return

        if not messagebox.askyesno(
            "Подтверждение",
            f"Перезаписать файл {self._dir_label()}/{filename} на сервере?\n"
            "Это сразу повлияет на все следующие запуски генерации.",
        ):
            return

        content = self.text_area.get("1.0", "end-1c")

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_dir}/{filename}"
            with sftp.open(remote_file, "w") as f:
                f.write(content.encode("utf-8"))
            sftp.close()
            self.info_var.set(f"Сохранено: {self._dir_label()}/{filename}")
            messagebox.showinfo("Готово", f"Файл {self._dir_label()}/{filename} обновлён на сервере.")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось сохранить файл: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass


class PromptEditorWindow(tk.Toplevel):
    """Окно редактора промптов с двумя вкладками — товарные промпты и
    промпты SEO-страниц умного фильтра. Каждая вкладка работает со своей
    отдельной папкой на сервере и своим набором команд, не пересекаясь."""

    def __init__(self, app, host, port, user, password, remote_path, section):
        super().__init__(app.root)
        self.title("Редактор промптов")
        self.geometry("820x680")

        hint = (
            "Плейсхолдеры, которые скрипт подставит автоматически: "
            "{{NAME}} {{SECTION}} {{PROPS}} {{EXTRA_BLOCK}} {{REWRITE_BLOCK}} "
            "(в rewrite_block.txt также доступен {{EXISTING_TEXT}}). Не удаляй их, если только не уверен."
        )

        notebook = ttk.Notebook(self)
        notebook.pack(fill="both", expand=True, padx=6, pady=6)

        product_tab = PromptEditorTab(
            notebook, app, host, port, user, password, remote_path,
            remote_dir=f"{remote_path}/prompts",
            list_cmd="listprompts",
            which_cmd="whichprompt",
            bind_cmd="bindprompt",
            hint_text=hint,
            section=section,
        )
        notebook.add(product_tab, text="Товарные промпты")

        smartfilter_tab = PromptEditorTab(
            notebook, app, host, port, user, password, remote_path,
            remote_dir=f"{remote_path}/prompts/smartfilter",
            list_cmd="listsmartfilterprompts",
            which_cmd="whichsmartfilterprompt",
            bind_cmd="bindsmartfilterprompt",
            hint_text=hint,
            section=section,
        )
        notebook.add(smartfilter_tab, text="Промпты SEO-страниц фильтра")


class SmartFilterWindow(tk.Toplevel):
    """Окно для генерации текстов страниц умного фильтра (URL вида
    .../filter/code-is-value/.../) по вставленному списку ссылок — раздел
    для каждой ссылки определяется автоматически на сервере."""

    def __init__(self, app, host, port, user, password, remote_path):
        super().__init__(app.root)
        self.app = app
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.remote_path = remote_path

        self.title("Генерация по списку ссылок (SEO-фильтры)")
        self.geometry("800x600")

        hint = (
            "Вставь список ссылок на страницы умного фильтра — по одной на строку. "
            "Раздел и параметры фильтра для каждой ссылки определятся автоматически из самого URL."
        )
        ttk.Label(self, text=hint, wraplength=760).pack(anchor="w", padx=12, pady=(10, 4))

        self.text_area = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 10), height=20)
        self.text_area.pack(fill="both", expand=True, padx=10, pady=8)
        self._setup_paste(self.text_area)

        btn_frame = ttk.Frame(self)
        btn_frame.pack(fill="x", padx=10, pady=(0, 10))

        self.run_btn = ttk.Button(btn_frame, text="Запустить генерацию", command=self._run)
        self.run_btn.pack(side="left")

        self.status_var = tk.StringVar(value="")
        ttk.Label(btn_frame, textvariable=self.status_var, foreground="blue").pack(side="left", padx=10)

    def _setup_paste(self, widget):
        # Та же особенность Tkinter, что и в остальных полях приложения —
        # Ctrl+V может не срабатывать при русской раскладке клавиатуры.
        def paste(event=None):
            widget.event_generate("<<Paste>>")
            return "break"

        def on_key(event):
            if event.state & 0x4 and event.keycode == 86:
                return paste()

        widget.bind("<Key>", on_key)

        menu = tk.Menu(widget, tearoff=0)
        menu.add_command(label="Вставить", command=paste)

        def show_menu(event):
            menu.tk_popup(event.x_root, event.y_root)

        widget.bind("<Button-3>", show_menu)

    def _connect(self):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=self.host, port=self.port, username=self.user, password=self.password, timeout=15)
        # keepalive — некоторые роутеры/файрволы разрывают "неактивные" TCP-
        # соединения, если не видят в них трафика; keepalive шлёт пустые
        # пакеты каждые 30 секунд, чтобы соединение не считалось неактивным.
        transport = client.get_transport()
        if transport:
            transport.set_keepalive(30)
        return client

    def _run(self):
        raw = self.text_area.get("1.0", "end").strip()
        urls = [line.strip() for line in raw.splitlines() if line.strip()]

        if not urls:
            messagebox.showerror("Ошибка", "Список ссылок пуст.")
            return

        if not messagebox.askyesno(
            "Подтверждение",
            f"Запустить генерацию для {len(urls)} ссылок?\n\n"
            "Это потратит запросы к API — по одному на каждую новую комбинацию "
            "(уже сгенерированные ранее комбинации будут пропущены).",
        ):
            return

        self.run_btn.configure(state="disabled")
        self.status_var.set("Загружаю список на сервер...")

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_path}/smartfilter_urls_input.txt"
            with sftp.open(remote_file, "w") as f:
                f.write(("\n".join(urls) + "\n").encode("utf-8"))
            sftp.close()
            client.close()

            self.status_var.set(f"Список загружен ({len(urls)} ссылок). Запускаю генерацию в фоне...")

            inner_cmd = "php ai_generate_descriptions.php gensmartfilter smartfilter_urls_input.txt"
            self.app._start_remote_job("smartfilter_gen", "urls", inner_cmd)

            messagebox.showinfo(
                "Запущено",
                "Генерация запущена в фоне на сервере. Прогресс смотри в окне 'Лог выполнения' главного окна приложения.",
            )
            self.destroy()

        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось запустить: {e}")
            self.run_btn.configure(state="normal")
            self.status_var.set("")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass


class ReportViewerWindow(tk.Toplevel):
    """Окно для удобного просмотра уже сгенерированных текстов раздела —
    забирает ai_desc_report_section_<ID>.json с сервера через SFTP,
    аккуратно разбирает JSON (без "битых" \\n и \\/ как при простом
    копировании сырого файла) и показывает читаемым списком: ID, название,
    статус и сам текст описания каждого товара."""

    def __init__(self, app, host, port, user, password, remote_path, section):
        super().__init__(app.root)
        self.app = app
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.remote_path = remote_path
        self.section = section

        self.title(f"Сгенерированные тексты — раздел {section}")
        self.geometry("900x650")

        top_frame = ttk.Frame(self)
        top_frame.pack(fill="x", padx=10, pady=8)

        ttk.Label(top_frame, text=f"Раздел: {section}").pack(side="left")
        ttk.Button(top_frame, text="Обновить", command=self._load_report).pack(side="left", padx=8)

        self.info_var = tk.StringVar(value="Загрузка...")
        ttk.Label(self, textvariable=self.info_var, foreground="blue").pack(anchor="w", padx=12)

        self.text_area = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 10))
        self.text_area.pack(fill="both", expand=True, padx=10, pady=8)
        setup_text_editing_shortcuts(self.text_area)

        self._load_report()

    def _connect(self):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=self.host, port=self.port, username=self.user, password=self.password, timeout=15)
        # keepalive — некоторые роутеры/файрволы разрывают "неактивные" TCP-
        # соединения, если не видят в них трафика; keepalive шлёт пустые
        # пакеты каждые 30 секунд, чтобы соединение не считалось неактивным.
        transport = client.get_transport()
        if transport:
            transport.set_keepalive(30)
        return client

    def _load_report(self):
        self.info_var.set("Загрузка...")
        self.text_area.configure(state="normal")
        self.text_area.delete("1.0", "end")
        self.text_area.insert("1.0", "Загружаю отчёт с сервера...")
        self.update_idletasks()

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_path}/ai_desc_report_section_{self.section}.json"
            with sftp.open(remote_file, "r") as f:
                raw = f.read().decode("utf-8", errors="replace")
            sftp.close()

            try:
                items = json.loads(raw)
            except json.JSONDecodeError as e:
                self.text_area.delete("1.0", "end")
                self.text_area.insert("1.0", f"Файл найден, но не удалось разобрать JSON:\n{e}\n\n--- Сырое содержимое ---\n{raw}")
                self.info_var.set("Ошибка разбора файла")
                return

            if not items:
                self.text_area.delete("1.0", "end")
                self.text_area.insert("1.0", "Отчёт пуст — для этого раздела ещё ничего не сгенерировано.")
                self.info_var.set("Пусто")
                return

            approved = sum(1 for it in items if it.get("status") == "approved")
            applied = sum(1 for it in items if it.get("status") == "applied")
            errors = sum(1 for it in items if it.get("status") == "error")

            lines = []
            for it in items:
                lines.append("=" * 70)
                lines.append(f"ID: {it.get('id', '')}")
                lines.append(f"Название: {it.get('name', '')}")
                lines.append(f"Статус: {it.get('status', '')}")
                if it.get("status") == "error":
                    lines.append(f"Ошибка: {it.get('error', '')}")
                else:
                    lines.append("")
                    lines.append(it.get("detail_text", "(пусто)"))
                lines.append("")

            self.text_area.delete("1.0", "end")
            self.text_area.insert("1.0", "\n".join(lines))
            self.info_var.set(
                f"Всего записей: {len(items)} · approved: {approved} · applied: {applied} · error: {errors}"
            )

        except FileNotFoundError:
            self.text_area.delete("1.0", "end")
            self.text_area.insert(
                "1.0",
                f"Файл отчёта не найден на сервере:\n{self.remote_path}/ai_desc_report_section_{self.section}.json\n\n"
                "Возможно, генерация для этого раздела ещё не запускалась.",
            )
            self.info_var.set("Файл не найден")
        except Exception as e:
            self.text_area.delete("1.0", "end")
            self.text_area.insert("1.0", f"Ошибка загрузки: {e}")
            self.info_var.set("Ошибка")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass


class PromptTesterWindow(tk.Toplevel):
    """"Песочница" для промптов — позволяет написать/вставить черновик
    инструкции для ИИ и сразу проверить её на реальном товаре или странице
    умного фильтра, не выходя из приложения и не создавая (пока сам не
    попросишь) никаких файлов на сервере. Ничего не сохраняется в отчёт
    и не пишется в Битрикс — чисто тестовый запуск одного запроса к API."""

    def __init__(self, app, host, port, user, password, remote_path):
        super().__init__(app.root)
        self.app = app
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.remote_path = remote_path

        self.title("Тестирование промпта")
        self.geometry("900x980")

        top_frame = ttk.Frame(self)
        top_frame.pack(fill="x", padx=10, pady=8)

        self.mode_var = tk.StringVar(value="product")
        ttk.Radiobutton(top_frame, text="Товар (по ID)", variable=self.mode_var, value="product",
                        command=self._update_mode_label).pack(side="left")
        ttk.Radiobutton(top_frame, text="Страница умного фильтра (по ссылке)", variable=self.mode_var, value="smartfilter",
                        command=self._update_mode_label).pack(side="left", padx=(10, 0))

        input_frame = ttk.Frame(self)
        input_frame.pack(fill="x", padx=10, pady=(0, 8))

        self.input_label_var = tk.StringVar(value="ID или ссылки на товары (можно несколько — по одному на строке или через запятую):")
        ttk.Label(input_frame, textvariable=self.input_label_var).pack(anchor="w")
        self.input_text = scrolledtext.ScrolledText(input_frame, wrap="word", font=("Consolas", 10), height=3)
        self.input_text.pack(fill="x", expand=True)
        setup_text_editing_shortcuts(self.input_text)

        ttk.Label(self, text="Текст промпта (плейсхолдеры: {{ID}} {{NAME}} {{SECTION}} {{PROPS}} {{EXTRA_BLOCK}} {{REWRITE_BLOCK}}):").pack(anchor="w", padx=12)
        ttk.Label(
            self,
            text=(
                "Чтобы защитить часть промпта от изменений кнопкой \"Предложить правки\" — оберни её так:\n"
                "<!-- НЕ ТРОГАТЬ -->\n## Твой раздел\nТекст, который должен остаться неизменным.\n<!-- /НЕ ТРОГАТЬ -->"
            ),
            foreground="#555555", justify="left",
        ).pack(anchor="w", padx=12, pady=(0, 4))

        self.prompt_area = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 10), height=14)
        self.prompt_area.pack(fill="both", expand=True, padx=10, pady=(0, 8))
        setup_text_editing_shortcuts(self.prompt_area)

        btn_frame = ttk.Frame(self)
        btn_frame.pack(fill="x", padx=10, pady=(0, 8))

        self.run_btn = ttk.Button(btn_frame, text="Сгенерировать", command=self._run_test)
        self.run_btn.pack(side="left")

        ttk.Button(btn_frame, text="Сохранить как файл промпта...", command=self._save_as_prompt_file).pack(side="left", padx=8)

        self.suggest_btn = ttk.Button(btn_frame, text="Предложить правки промпта", command=self._suggest_prompt_fixes)
        self.suggest_btn.pack(side="left", padx=8)

        self.status_var = tk.StringVar(value="")
        ttk.Label(btn_frame, textvariable=self.status_var, foreground="blue").pack(side="left", padx=10)

        ttk.Label(self, text="Результат:").pack(anchor="w", padx=12)

        self.result_area = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 10), height=10)
        self.result_area.pack(fill="both", expand=True, padx=10, pady=(0, 8))
        setup_text_editing_shortcuts(self.result_area)

        ttk.Label(self, text="Предложения по правке промпта (от ИИ, на основе результата выше):").pack(anchor="w", padx=12)

        self.suggestions_area = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 10), height=10)
        self.suggestions_area.pack(fill="both", expand=True, padx=10, pady=(0, 10))
        setup_text_editing_shortcuts(self.suggestions_area)

    def _update_mode_label(self):
        if self.mode_var.get() == "product":
            self.input_label_var.set("ID или ссылки на товары (можно несколько — по одному на строке или через запятую):")
        else:
            self.input_label_var.set("Ссылки на страницы фильтра (по одной на строке):")

    def _connect(self):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=self.host, port=self.port, username=self.user, password=self.password, timeout=15)
        transport = client.get_transport()
        if transport:
            transport.set_keepalive(30)
        return client

    def _run_test(self):
        prompt_text = self.prompt_area.get("1.0", "end-1c").strip()
        raw_input = self.input_text.get("1.0", "end-1c").strip()

        if not prompt_text:
            messagebox.showerror("Ошибка", "Вставь текст промпта для теста.")
            return
        if not raw_input:
            messagebox.showerror("Ошибка", "Укажи ID товара(ов) или ссылку(и) на страницу фильтра.")
            return

        # Разбираем список значений — по строкам и/или через запятую.
        values = []
        for line in raw_input.splitlines():
            for part in line.split(","):
                part = part.strip()
                if part:
                    values.append(part)

        if not values:
            messagebox.showerror("Ошибка", "Не удалось распознать ни одного ID/ссылки.")
            return

        if len(values) > 10:
            if not messagebox.askyesno(
                "Подтверждение",
                f"Указано {len(values)} значений — это {len(values)} отдельных запросов к API. Продолжить?",
            ):
                return

        self.run_btn.configure(state="disabled")
        self.status_var.set(f"Генерирую 1 из {len(values)}...")
        self.result_area.delete("1.0", "end")
        self.update_idletasks()

        thread = threading.Thread(target=self._run_test_worker, args=(prompt_text, values), daemon=True)
        thread.start()

    def _run_test_worker(self, prompt_text, values):
        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_temp_file = f"{self.remote_path}/prompt_test_temp.txt"
            with sftp.open(remote_temp_file, "w") as f:
                f.write(prompt_text.encode("utf-8"))
            sftp.close()

            if self.mode_var.get() == "product":
                cmd_name = "testpromptproduct"
            else:
                cmd_name = "testpromptsmartfilter"

            api_key = self.app.api_key_var.get().strip()
            model = self.app.model_var.get().strip()

            for i, value in enumerate(values, start=1):
                self.after(0, lambda i=i: self.status_var.set(f"Генерирую {i} из {len(values)}..."))

                safe_input = shlex.quote(value)
                cmd = (
                    f"cd {self.remote_path} && "
                    f"ROUTER_CHEAP_API_KEY={shlex.quote(api_key)} ROUTER_CHEAP_MODEL={shlex.quote(model)} "
                    f"php ai_generate_descriptions.php {cmd_name} {safe_input} prompt_test_temp.txt"
                )
                try:
                    _, out, err = client.exec_command(cmd, timeout=180)
                    out.channel.recv_exit_status()
                    result_text = out.read().decode("utf-8", errors="replace")
                    err_text = err.read().decode("utf-8", errors="replace").strip()
                    if err_text:
                        result_text += f"\n\n[stderr]:\n{err_text}"
                except Exception as e:
                    result_text = f"ОШИБКА при обработке '{value}': {e}"

                block = f"\n{'#' * 70}\n# [{i}/{len(values)}] {value}\n{'#' * 70}\n\n{result_text}\n"
                self.after(0, lambda b=block: self._append_result(b))

            self.after(0, self._finish_test)
        except Exception as e:
            self.after(0, lambda: self._append_result(f"ОШИБКА: {e}"))
            self.after(0, self._finish_test)
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _append_result(self, text):
        self.result_area.insert("end", text)
        self.result_area.see("end")

    def _finish_test(self):
        self.status_var.set("Готово.")
        self.run_btn.configure(state="normal")

    def _suggest_prompt_fixes(self):
        prompt_text = self.prompt_area.get("1.0", "end-1c").strip()
        results_text = self.result_area.get("1.0", "end-1c").strip()

        if not prompt_text:
            messagebox.showerror("Ошибка", "Поле с промптом пустое.")
            return
        if not results_text:
            messagebox.showerror("Ошибка", "Сначала сгенерируй хотя бы один результат — правки предлагаются на основе него.")
            return

        self.suggest_btn.configure(state="disabled")
        self.status_var.set("Анализирую и подбираю правки...")
        self.suggestions_area.delete("1.0", "end")
        self.update_idletasks()

        thread = threading.Thread(target=self._suggest_prompt_fixes_worker, args=(prompt_text, results_text), daemon=True)
        thread.start()

    def _suggest_prompt_fixes_worker(self, prompt_text, results_text):
        client = None
        try:
            critique_prompt = (
                "Ты - эксперт по промпт-инжинирингу для ИИ-генерации текстов.\n\n"
                "Ниже дан ТЕКСТ ПРОМПТА-ИНСТРУКЦИИ для генератора описаний товаров "
                "и РЕЗУЛЬТАТЫ, которые эта инструкция дала на реальных товарах.\n\n"
                "Проанализируй результаты на предмет проблем: выдуманные факты, которых "
                "нет в исходных данных; нарушение собственных правил промпта (в том числе "
                "неподходящий объём текста - слишком короткий или слишком длинный относительно "
                "заданного в самом промпте диапазона); повторяющаяся структура между карточками; "
                "рекламные клише; и другие проблемы качества.\n\n"
                "ВАЖНО: если в тексте промпта есть фрагменты между метками "
                "<!-- НЕ ТРОГАТЬ --> и <!-- /НЕ ТРОГАТЬ --> - эти фрагменты ЗАЩИЩЕНЫ. "
                "НИКОГДА не предлагай правки, которые меняют, удаляют или пересекаются с "
                "текстом внутри таких меток, даже если тебе кажется, что там есть проблема. "
                "Полностью игнорируй защищённые фрагменты при поиске правок - работай "
                "только с остальным текстом промпта.\n\n"
                "Для КАЖДОЙ найденной проблемы выведи ОДНУ правку в СТРОГО следующем формате "
                "(можно несколько блоков подряд, если проблем несколько):\n\n"
                "===EDIT===\n"
                "OLD:\n"
                "<точная дословная цитата фрагмента текущего промпта, который нужно заменить - "
                "скопируй буквально, без единого изменения символов>\n"
                "NEW:\n"
                "<новый текст, которым заменить этот фрагмент>\n"
                "===END===\n\n"
                "Если нужно ДОБАВИТЬ новое правило, а не заменить существующее - в OLD укажи "
                "последнюю строку раздела, после которой добавляешь, а в NEW включи эту же "
                "строку целиком плюс новый текст после неё.\n\n"
                "OLD должен встречаться в тексте промпта РОВНО ОДИН РАЗ - если фрагмент "
                "короткий и может повторяться, включи больше окружающего текста, чтобы он "
                "стал уникальным.\n\n"
                "Если результаты уже хорошего качества и проблем не нашлось - выведи ровно "
                "одну строку без пояснений: НЕТ_ПРАВОК\n\n"
                "Не пиши НИКАКИХ пояснений, вступлений или комментариев вне этого формата - "
                "только блоки ===EDIT===...===END=== или строка НЕТ_ПРАВОК.\n\n"
                "=== ТЕКУЩИЙ ПРОМПТ ===\n"
                f"{prompt_text}\n\n"
                "=== СГЕНЕРИРОВАННЫЕ РЕЗУЛЬТАТЫ ===\n"
                f"{results_text}\n"
            )

            client = self._connect()
            sftp = client.open_sftp()
            remote_temp_file = f"{self.remote_path}/prompt_critique_temp.txt"
            with sftp.open(remote_temp_file, "w") as f:
                f.write(critique_prompt.encode("utf-8"))
            sftp.close()

            api_key = self.app.api_key_var.get().strip()
            model = self.app.model_var.get().strip()

            cmd = (
                f"cd {self.remote_path} && "
                f"ROUTER_CHEAP_API_KEY={shlex.quote(api_key)} ROUTER_CHEAP_MODEL={shlex.quote(model)} "
                f"php ai_generate_descriptions.php rawapicall prompt_critique_temp.txt"
            )
            _, out, err = client.exec_command(cmd, timeout=180)
            out.channel.recv_exit_status()
            raw_response = out.read().decode("utf-8", errors="replace")
            err_text = err.read().decode("utf-8", errors="replace").strip()

            # Разбираем блоки ===EDIT===...OLD:...NEW:...===END=== и применяем
            # их к ИСХОДНОМУ тексту промпта прямо в Python — так итоговый текст
            # гарантированно полный, без риска, что модель его обрежет/сократит
            # при попытке воспроизвести весь документ целиком.
            edit_pattern = re.compile(
                r"===EDIT===\s*OLD:\s*\n(.*?)\nNEW:\s*\n(.*?)\n===END===",
                re.DOTALL,
            )
            edits = edit_pattern.findall(raw_response)

            updated_prompt = prompt_text
            applied = 0
            skipped = []

            if "НЕТ_ПРАВОК" in raw_response and not edits:
                summary = "ИИ не нашёл проблем — правок не предложено. Текущий промпт оставлен без изменений."
            elif not edits:
                summary = (
                    "Не удалось разобрать ответ ИИ в ожидаемом формате правок.\n"
                    "Сырой ответ модели показан ниже для справки:\n\n" + raw_response
                )
            else:
                # Дополнительная защита на уровне кода (не только инструкцией) —
                # если фрагмент OLD пересекается с текстом внутри меток
                # <!-- НЕ ТРОГАТЬ -->...<!-- /НЕ ТРОГАТЬ -->, правку не применяем,
                # даже если модель предложила её вопреки инструкции.
                protected_ranges = [
                    m.span() for m in re.finditer(
                        r"<!--\s*НЕ ТРОГАТЬ\s*-->.*?<!--\s*/НЕ ТРОГАТЬ\s*-->",
                        updated_prompt, re.DOTALL,
                    )
                ]
                protected_skipped = []

                for old_fragment, new_fragment in edits:
                    count = updated_prompt.count(old_fragment)
                    if count != 1:
                        skipped.append((old_fragment, count))
                        continue

                    pos = updated_prompt.find(old_fragment)
                    frag_end = pos + len(old_fragment)
                    overlaps_protected = any(
                        pos < p_end and frag_end > p_start
                        for p_start, p_end in protected_ranges
                    )
                    if overlaps_protected:
                        protected_skipped.append(old_fragment)
                        continue

                    updated_prompt = updated_prompt.replace(old_fragment, new_fragment)
                    applied += 1

                summary_lines = [f"Применено правок: {applied} из {len(edits)}.\n"]
                if protected_skipped:
                    summary_lines.append(f"Пропущено (задевает защищённую зону <!-- НЕ ТРОГАТЬ -->) — {len(protected_skipped)}:\n")
                    for frag in protected_skipped:
                        preview = frag[:80] + ("..." if len(frag) > 80 else "")
                        summary_lines.append(f"  - \"{preview}\"")
                if skipped:
                    summary_lines.append(f"Пропущено (не нашли уникальное совпадение) — {len(skipped)}:\n")
                    for frag, count in skipped:
                        preview = frag[:80] + ("..." if len(frag) > 80 else "")
                        summary_lines.append(f"  - [{count} совпадений] \"{preview}\"")
                summary_lines.append("\n=== ИТОГОВЫЙ ПОЛНЫЙ ПРОМПТ (готов к копированию) ===\n")
                summary_lines.append(updated_prompt)
                summary = "\n".join(summary_lines)

            if err_text:
                summary += f"\n\n[stderr]:\n{err_text}"

            self.after(0, lambda: self._show_suggestions(summary))
        except Exception as e:
            self.after(0, lambda: self._show_suggestions(f"ОШИБКА: {e}"))
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _show_suggestions(self, text):
        self.suggestions_area.delete("1.0", "end")
        self.suggestions_area.insert("1.0", text)
        self.status_var.set("Готово.")
        self.suggest_btn.configure(state="normal")

    def _save_as_prompt_file(self):
        prompt_text = self.prompt_area.get("1.0", "end-1c")
        if not prompt_text.strip():
            messagebox.showerror("Ошибка", "Нечего сохранять — поле с промптом пустое.")
            return

        is_smartfilter = self.mode_var.get() == "smartfilter"
        target_dir = f"{self.remote_path}/prompts/smartfilter" if is_smartfilter else f"{self.remote_path}/prompts"
        target_label = "prompts/smartfilter" if is_smartfilter else "prompts"

        filename = simpledialog.askstring(
            "Сохранить как файл промпта",
            f"Имя файла в папке {target_label}/ (например: pipes.txt):",
            parent=self,
        )
        if not filename:
            return
        filename = filename.strip()
        if not filename.endswith(".txt"):
            filename += ".txt"

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{target_dir}/{filename}"
            with sftp.open(remote_file, "w") as f:
                f.write(prompt_text.encode("utf-8"))
            sftp.close()
            messagebox.showinfo("Готово", f"Сохранено: {target_label}/{filename}\n\nНе забудь привязать его к разделу через «Редактировать промпт», если нужно использовать в реальной генерации.")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось сохранить: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass


if __name__ == "__main__":
    root = tk.Tk()
    app = App(root)
    root.mainloop()