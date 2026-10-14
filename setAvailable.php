<?
require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

use \Bitrix\Main\Loader;
use \Bitrix\Iblock\IblockTable;
use \Bitrix\Iblock\PropertyTable;
use \Bitrix\Iblock\PropertyEnumerationTable;

global $USER;

if (!$USER->isAdmin())
{
	echo 'Доступ запрещен!';
	die();
}

Loader::includeModule('iblock');

$arErrors = [];

$iblockCode = 'aspro_allcorp3metal_catalog';
$propertyCode = 'STATUS';

$arIblock = IblockTable::getList([
	'filter' => ['CODE' => $iblockCode],
	'select' => ['ID'],
])->fetch();

if ($arIblock)
{
	$arProperty = PropertyTable::getList([
		'filter' => ['IBLOCK_ID' => $arIblock['ID'], 'CODE' => $propertyCode],
		'select' => ['ID', 'PROPERTY_TYPE'],
	])->fetch();

	if ($arProperty)
	{
		if ($arProperty['PROPERTY_TYPE'] == 'L')
		{
			$arEnums = [];
			$rsEnum = PropertyEnumerationTable::getList([
				'filter' => ['PROPERTY_ID' => $arProperty['ID']],
				'select' => ['ID', 'VALUE'],
			]);
			while ($arEnum = $rsEnum->fetch())
			{
				$arEnums[$arEnum['ID']] = $arEnum['VALUE'];
			}
		}
		else
		{
			$arErrors[] = 'Тип свойства "'.$propertyCode.'" не является списком';
		}
	}
	else
	{
		$arErrors[] = 'Не найдено свойство с кодом "'.$propertyCode.'"';
	}
}
else
{
	$arErrors[] = 'Не найден инфоблок с кодом "'.$iblockCode.'"';
}

if (!$arErrors)
{
	$value = $_SESSION['SET_AVAILABLE_VALUE'] ?: $_POST['value'];
	if ($value)
	{
		$step = (int)$_GET['step'] ?: 0;
		$limit = 100;

		$rsAllElements = \CIBlockElement::GetList([], ['IBLOCK_ID' => $arIblock['ID'], '!PROPERTY_'.$propertyCode => $value]);
		$count = $rsAllElements->SelectedRowsCount();
		if ($count)
		{
			echo 'Осталось элементов: ' . $count . '<br>';
			$rsElements = \CIBlockElement::GetList([], ['IBLOCK_ID' => $arIblock['ID'], '!PROPERTY_'.$propertyCode => $value], false, ['nTopCount' => $limit], ['ID']);
			while ($arElement = $rsElements->Fetch())
			{
				CIBlockElement::SetPropertyValuesEx($arElement['ID'], false, array($propertyCode => $value));
			}

			if ($_POST['value'])
			{
				$_SESSION['SET_AVAILABLE_VALUE'] = $_POST['value'];
			}

			//reload
			$curPage = $GLOBALS['APPLICATION']->GetCurPage(false);
			header('Location: '.$curPage.'?step='.($step+1));
		}
		else
		{
			unset($_SESSION['SET_AVAILABLE_VALUE']);
			header('Location: '.$curPage.'?success=Y');
		}
	}
}
?>

<h1>Установить доступность для всех товаров</h1>

<?if($arErrors):?>
	<div><?=implode('<br>', $arErrors)?></div>
<?endif?>

<?if($_GET['success'] == 'Y'):?>
	<div>Операция завершена</div>
<?endif?>

<?if($arEnums):?>
	<form method="post">
		<select name="value">
			<?foreach($arEnums as $key => $value):?>
				<option value="<?=$key?>"><?=$value?></option>
			<?endforeach?>
		</select>
		<button type="submit">Изменить доступность</button>
	</form>
<?endif?>