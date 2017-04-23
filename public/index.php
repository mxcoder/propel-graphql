<?php
require '../vendor/autoload.php';
require '../tmp/config.php';

use GraphQL\Type\Definition\Config;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Schema;
use GraphQL\GraphQL;
use Propel\Generator\Model\PropelTypes;

// Disable default PHP error reporting - we have better one for debug mode (see bellow)
ini_set('display_errors', 1);

if (!empty($_GET['debug'])) {
    // Enable additional validation of type configs
    // (disabled by default because it is costly)
    Config::enableValidation();

    // Catch custom errors (to report them in query results if debugging is enabled)
    $phpErrors = [];
    set_error_handler(function($severity, $message, $file, $line) use (&$phpErrors) {
        $phpErrors[] = new ErrorException($message, 0, $severity, $file, $line);
    });
}

try {
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
            $relation = $foreignKey->getRelation();
            $fks[$model][] = [
                'name' => $relation->getName(),
                'type' => $relation->getType(),
            ];
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
        $typeFields = function () use (&$objectTypes, $typeConf, $fks, $model) {
            foreach ($fks[$model] as $relatedModel) {
                $relName = $relatedModel['name'];
                $relType = $objectTypes[$relatedModel['name']];
                $typeConf['fields'][$relName] = [
                    'type' => $relatedModel['type'] === \Propel\Runtime\Map\RelationMap::ONE_TO_MANY ? Type::listOf($relType) : $relType,
                    'description' => "{$model} related {$relName}",
                    'resolve' => function($root, $args, $context, ResolveInfo $info) {
                        /** @var \App\Models\Book $root */
                        $getter = "get{$info->fieldName}";
                        return $root->$getter();
                    }
                ];
            }
            return $typeConf['fields'];
        };
        $typeConf['fields'] = $typeFields;
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
        $objectTypes[$model] = $objectType;
    }

    GraphQL::setDefaultFieldResolver(function($source, $args, $context, ResolveInfo $info) {
        return $source->getByName($info->fieldName);
    });

    $queryType = new ObjectType([
        'name' => 'Query',
        'fields' => $top_fields,
    ]);

    $schema = new Schema([
        'query' => $queryType,
        'mutation' => null,
        'types' => array_values($objectTypes)
    ]);

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    $query = $input['query'];
    $variableValues = isset($input['variables']) ? $input['variables'] : null;
    if (null === $query) {
        $query = '
        {
            Author {
                FirstName
                Book {
                    Title
                }
            }
        }';
    }

    $result = GraphQL::execute($schema, $query, null, null, $variableValues);
} catch (\Exception $e) {
    $result = ['error' => ['message' => $e->getMessage()]];
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result);
