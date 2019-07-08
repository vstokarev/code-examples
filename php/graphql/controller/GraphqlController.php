<?php

namespace app\controllers;

use app\graphql\TypeRegistry;
use app\graphql\types\QueryType;
use GraphQL\Error\{Debug, FormattedError};
use GraphQL\GraphQL;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaPrinter;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\web\Controller;

class GraphqlController extends Controller
{
	public $enableCsrfValidation = false;

	public function actionIndex()
	{
		ini_set('display_errors', 0);

		$debug = false;
		if (!empty($_GET['debug'])) {
			set_error_handler(function($severity, $message, $file, $line) use (&$phpErrors) {
				throw new \ErrorException($message, 0, $severity, $file, $line);
			});
			$debug = Debug::INCLUDE_DEBUG_MESSAGE | Debug::INCLUDE_TRACE;
		}

		try {
			if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
				$raw = file_get_contents('php://input') ?: '';
				$data = json_decode($raw, true) ?: [];
			} else {
				$data = $_REQUEST;
			}

			$data += ['query' => null, 'variables' => null];

			$typesConfig = include __DIR__ . '/../graphql/TypesConfig.php';
			$schemaFilePath = \Yii::getAlias('@app/graphql/schema.graphql');
			$schema = BuildSchema::build(
				file_get_contents($schemaFilePath),
				function ($typeConfig, $typeDefinitionNode) use ($typesConfig) {
					$typeName = $typeConfig['name'];

					/*if ($typeDefinitionNode->kind == NodeKind::SCALAR_TYPE_DEFINITION) {
						$typeConfig['serialize'] = function ($value) {
							return 'Some prefix: ' . $value;
						};

						return $typeConfig;
					}*/

					if (isset($typesConfig[$typeName])) {
						$typeConfig = ArrayHelper::merge($typeConfig, $typesConfig[$typeName]);
					}

					if (!isset($typeConfig['resolveField'])) {
						$className = '\\app\\graphql\\types\\' . $typeName . 'Type';
						if (class_exists($className)) {
							$typeConfig['resolveField'] = function($source, $args, $context, $resolveInfo) use ($className) {
								$objectType = new $className();
								return $objectType->defaultFieldResolver($source, $args, $context, $resolveInfo);
							};
						}
					}

					return $typeConfig;
				},
				['commentDescriptions' => true]
			);

			$result = GraphQL::executeQuery(
				$schema,
				$data['query'],
				null, //$rootValue
				null,
				(array) $data['variables']
			);

			$output = $result->toArray($debug);
		} catch (\Exception $exception) {
			$httpStatus = 500;
			$output['errors'] = [
				FormattedError::createFromException($exception, $debug)
			];
		}

		header('Content-Type: application/json', true, 200);
		return json_encode($output);
	}
}