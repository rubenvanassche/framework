<?php

namespace Illuminate\Routing;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Contracts\PolymorphicRouteBinding;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;

class ImplicitRouteBinding
{
    /**
     * Resolve the implicit route bindings for the given route.
     *
     * @param \Illuminate\Container\Container $container
     * @param \Illuminate\Routing\Route $route
     *
     * @return void
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public static function resolveForRoute($container, $route)
    {
        $parameters = $route->parameters();

        foreach ($route->signatureParameters([UrlRoutable::class, PolymorphicRouteBinding::class]) as $parameter) {
            if (! $parameterName = static::getParameterName($parameter->getName(), $parameters)) {
                continue;
            }

            $parameterValue = $parameters[$parameterName];

            if ($parameterValue instanceof UrlRoutable) {
                continue;
            }

            $parameterReflection = Reflector::getParameterClassName($parameter);

            if(static::isPolymorphic($parameterReflection)){
                [$model, $parameterValue] = explode('@', $parameterValue);

                $parameterReflection = Relation::getMorphedModel($model) ?? $model;
            }

            $instance = $container->make($parameterReflection);

            $parent = $route->parentOfParameter($parameterName);

            if ($parent instanceof UrlRoutable && in_array($parameterName, array_keys($route->bindingFields()))) {
                if (! $model = $parent->resolveChildRouteBinding(
                    $parameterName, $parameterValue, $route->bindingFieldFor($parameterName)
                )) {
                    throw (new ModelNotFoundException)->setModel(get_class($instance), [$parameterValue]);
                }
            } elseif (! $model = $instance->resolveRouteBinding($parameterValue, $route->bindingFieldFor($parameterName))) {
                throw (new ModelNotFoundException)->setModel(get_class($instance), [$parameterValue]);
            }

            $route->setParameter($parameterName, $model);
        }
    }

    /**
     * Return the parameter name if it exists in the given parameters.
     *
     * @param string $name
     * @param array $parameters
     *
     * @return string|null
     */
    protected static function getParameterName($name, $parameters)
    {
        if (array_key_exists($name, $parameters)) {
            return $name;
        }

        if (array_key_exists($snakedName = Str::snake($name), $parameters)) {
            return $snakedName;
        }
    }

    protected static function isPolymorphic($parameterReflection): bool
    {
        return interface_exists($parameterReflection) &&
            in_array(PolymorphicRouteBinding::class, class_implements($parameterReflection));
    }
}
