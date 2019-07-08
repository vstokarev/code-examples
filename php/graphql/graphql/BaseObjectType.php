<?php

namespace app\graphql;

use GraphQL\Executor\Executor;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;

class BaseObjectType extends ObjectType
{
	public function __construct($config = [])
	{
		$config['resolveField'] = function ($source, $args, $context, ResolveInfo $resolveInfo) {
			return $this->defaultFieldResolver($source, $args, $context, $resolveInfo);
		};

		parent::__construct($config);
	}

	public function defaultFieldResolver($source, $args, $context, ResolveInfo $resolveInfo) {
		$methodName = 'resolve' . ucfirst($resolveInfo->fieldName);
		if (method_exists($this, $methodName)) {
			return call_user_func([$this, $methodName], $source, $args, $context, $resolveInfo);
		} else {
			return Executor::defaultFieldResolver($source, $args, $context, $resolveInfo);
		}
	}
}