<?php
require '../vendor/autoload.php';
require '../tmp/config.php';

use GraphQL\GraphQL;
use GraphQL\Schema;
use GraphQL\Type\Definition\Config;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Propel\Generator\Model\PropelTypes;
use Propel\Runtime\ActiveQuery\PropelQuery;
use Propel\Runtime\Map\RelationMap;

// Disable default PHP error reporting - we have better one for debug mode (see bellow)
ini_set('display_errors', 0);

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
    $xml = simplexml_load_file(__DIR__ . '/../src/database/schema.xml');
    $tables = $xml->xpath("table");
    $pluralizer = new Propel\Common\Pluralizer\StandardEnglishPluralizer();

    $types = [];
    $relations = [];
    $topFields = [];
    $objectTypes = [];
    foreach ($tables as $table) {
        $model = (string)$table['phpName'];
        $baseFields = [];
        /** @var \Propel\Runtime\Map\TableMap $tableMap */
        $tableMap = call_user_func(["\\App\\Models\\Map\\{$model}TableMap", 'getTableMap']);
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
            $baseFields[$columnMap->getPhpName()] = [
                'type' => $type,
                'description' => "{$model} {$columnMap->getName()}",
            ];
        }
        $relations[$model] = [];
        foreach ($tableMap->getRelations() as $relation) {
            $relations[$model][] = [
                'name' => $relation->getName(),
                'type' => $relation->getType(),
            ];
        }
        $types[$model] = [
            'name' => $model,
            'description' => "Single {$model}",
            'fields' => $baseFields,
            'args' => $baseFields,
        ];
    }

    foreach ($types as $model => &$typeConf) {
        $args = $typeConf['args'];
        unset($typeConf['args']);

        $objectType = null;
        $typeFields = function () use (&$objectTypes, $typeConf, $relations, $model, $pluralizer) {
            foreach ($relations[$model] as $relatedModel) {
                $relType = $relatedModel['type'];
                $relName = $relatedModel['name'];
                $relModel = $objectTypes[$relName];
                $relEffectiveName = $relType === RelationMap::ONE_TO_MANY ?  $pluralizer->getPluralForm($relName) : $relName;
                $typeConf['fields'][$relEffectiveName] = [
                    'type' => $relType === RelationMap::ONE_TO_MANY ? Type::listOf($relModel) : $relModel,
                    'description' => "{$model} related {$relEffectiveName}",
                    'resolve' => function($root, $args, $context, ResolveInfo $info) {
                        $getter = "get{$info->fieldName}";
                        return $root->$getter();
                    }
                ];
            }
            return $typeConf['fields'];
        };
        $typeConf['fields'] = $typeFields;
        $objectType = new ObjectType($typeConf);
        $topFields[$model] = [
            'type' => $objectType,
            'args' => $args,
            'resolve' => function($root, $args, $context, ResolveInfo $info) use ($model) {
                $root = PropelQuery::from("\\App\\Models\\{$model}");
                if (!empty($args)) {
                    $root->filterByArray($args);
                }
                return $root->findOne();
            }
        ];
        $topFields[$pluralizer->getPluralForm($model)] = [
            'type' => Type::listOf($objectType),
            'args' => $args,
            'resolve' => function($root, $args, $context, ResolveInfo $info) use ($model) {
                $root = PropelQuery::from("\\App\\Models\\{$model}");
                if (!empty($args)) {
                    $root->filterByArray($args);
                }
                return $root->find();
            }
        ];
        $objectTypes[$model] = $objectType;
    }

    GraphQL::setDefaultFieldResolver(function($source, $args, $context, ResolveInfo $info) {
        $fieldName = $info->fieldName;
        $property = null;

        if (is_array($source) || $source instanceof \ArrayAccess) {
            if (isset($source[$fieldName])) {
                $property = $source[$fieldName];
            }
        } else if ($source instanceof \Propel\Runtime\ActiveRecord\ActiveRecordInterface) {
            $property = $source->getByName($info->fieldName);
        } else if (is_object($source)) {
            if (isset($source->{$fieldName})) {
                $property = $source->{$fieldName};
            }
        }
        return $property instanceof \Closure ? $property($source, $args, $context) : $property;
    });

    $queryType = new ObjectType([
        'name' => 'Query',
        'fields' => $topFields,
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
            __schema {
                types {
                    name
                }
            }
        }';
    }

    $result = GraphQL::executeAndReturnResult($schema, $query, null, null, $variableValues);
} catch (\Exception $e) {
    $result = ['error' => ['message' => $e->getMessage()]];
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result);
