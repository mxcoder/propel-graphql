<?php
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Propel\Generator\Model\PropelTypes;

require_once '../vendor/autoload.php';
require_once '../tmp/config.php';

ini_set('display_errors', 1);
//header('Content-Type: text/plain; charset=UTF-8');

$Pluralizer = new Propel\Common\Pluralizer\StandardEnglishPluralizer();
$xml = simplexml_load_file(__DIR__ . '/../src/database/schema.xml');
$tables = $xml->xpath("table");

$objectTypes = [];
$types = [];
$top_fields = [];
$fks = [];
foreach ($tables as $table) {
    $model = (string)$table['phpName'];
    $modelClass = "\\App\\Models\\{$model}";
    $queryClass = "\\App\\Models\\{$model}Query";
    $tableMapClass = "\\App\\Models\\Map\\{$model}TableMap";
    $modelFields = [];
    /** @var \Propel\Runtime\Map\TableMap $tableMap */
    $tableMap = call_user_func([$tableMapClass, 'getTableMap']);
    foreach ($tableMap->getColumns() as $columnMap) {
        switch ($columnMap->getType()) {
            case PropelTypes::TINYINT:
            case PropelTypes::SMALLINT:
            case PropelTypes::INTEGER:
            case PropelTypes::BIGINT:
                $type = Type::int();
                break;
            case PropelTypes::NUMERIC:
            case PropelTypes::DECIMAL:
            case PropelTypes::REAL:
            case PropelTypes::FLOAT:
            case PropelTypes::DOUBLE:
                $type = Type::float();
                break;
            default:
                $type = Type::string();
                break;
        }
        $modelFields[$columnMap->getPhpName()] = [
            'type' => $type,
            'description' => "{$model} {$columnMap->getName()}",
        ];
    }
    $fks[$model] = [];
    foreach ($tableMap->getForeignKeys() as $foreignKey) {
        $fks[$model][] = $foreignKey->getRelation()->getName();
    }
    $types[$model] = [
        'name' => $model,
        'description' => "Single {$model}",
        'fields' => $modelFields,
        'args' => $modelFields,
        'queryClass' => $queryClass,
    ];
}

foreach ($types as $model => &$typeConf) {
    $args = $typeConf['args'];
    unset($typeConf['args']);
    $queryClass = $typeConf['queryClass'];
    unset($typeConf['queryClass']);

    $objectType = null;
    foreach ($fks[$model] as $relatedModel) {
        $typeConf['fields'][$relatedModel] = [
            'type' => function () use (&$objectType) {
                return $objectType;
            },
            'description' => "{$model} related {$relatedModel}",
            'resolve' => function($root, $args, $context, ResolveInfo $info) {
                /** @var \App\Models\Book $root */
                $getter = "get{$info->fieldName}";
                return $root->$getter();
            }
        ];
    }
    $objectType = new ObjectType($typeConf);
    $top_fields[$model] = [
        'type' => $objectType,
        'args' => $args,
        'resolve' => function($root, $args, $context, ResolveInfo $info) use ($queryClass) {
            /** @var \Propel\Runtime\ActiveQuery\ModelCriteria $root */
            $root = new $queryClass;
            if (!empty($args)) {
                $root->filterByArray($args);
            }
            return $root->findOne();
        }
    ];
    $top_fields[$Pluralizer->getPluralForm($model)] = [
        'type' => Type::listOf($objectType),
        'args' => $args,
        'resolve' => function($root, $args, $context, ResolveInfo $info) use ($queryClass) {
            /** @var \Propel\Runtime\ActiveQuery\ModelCriteria $root */
            $root = new $queryClass;
            if (!empty($args)) {
                $root->filterByArray($args);
            }
            return $root->find();
        }
    ];
    $objectTypes[$model] = $objectType->toString();
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($objectTypes);