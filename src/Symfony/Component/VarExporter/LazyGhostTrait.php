<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter;

use Symfony\Component\VarExporter\Internal\Hydrator;
use Symfony\Component\VarExporter\Internal\LazyObjectRegistry as Registry;
use Symfony\Component\VarExporter\Internal\LazyObjectState;

trait LazyGhostTrait
{
    private int $lazyObjectId;

    /**
     * Creates a lazy-loading ghost instance.
     *
     * When the initializer is a closure, it should initialize all properties at
     * once and is given the instance to initialize as argument.
     *
     * When the initializer is an array of closures, it should be indexed by
     * properties and closures should accept 4 arguments: the instance to
     * initialize, the property to initialize, its write-scope, and its default
     * value. Each closure should return the value of the corresponding property.
     *
     * Properties should be indexed by their array-cast name, see
     * https://php.net/manual/language.types.array#language.types.array.casting
     *
     * @param \Closure(static):void|array<string, \Closure(static, string, ?string, mixed):mixed> $initializer
     * @param array<string, true> $skippedProperties An array indexed by the properties to skip, aka the ones
     *                                               that the initializer doesn't set when its a closure
     */
    public static function createLazyGhost(\Closure|array $initializer, array $skippedProperties = [], self $instance = null): static
    {
        if (self::class !== $class = $instance ? $instance::class : static::class) {
            $skippedProperties["\0".self::class."\0lazyObjectId"] = true;
        } elseif (\defined($class.'::LAZY_OBJECT_PROPERTY_SCOPES')) {
            Hydrator::$propertyScopes[$class] ??= $class::LAZY_OBJECT_PROPERTY_SCOPES;
        }

        $instance ??= (Registry::$classReflectors[$class] ??= new \ReflectionClass($class))->newInstanceWithoutConstructor();
        Registry::$defaultProperties[$class] ??= (array) $instance;
        $instance->lazyObjectId = $id = spl_object_id($instance);
        Registry::$states[$id] = new LazyObjectState($initializer, $skippedProperties);
        $onlyProperties = \is_array($initializer) ? $initializer : null;

        foreach (Registry::$classResetters[$class] ??= Registry::getClassResetters($class) as $reset) {
            $reset($instance, $skippedProperties, $onlyProperties);
        }

        return $instance;
    }

    /**
     * Returns whether the object is initialized.
     */
    public function isLazyObjectInitialized(): bool
    {
        if (!$state = Registry::$states[$this->lazyObjectId ?? ''] ?? null) {
            return true;
        }

        if (!\is_array($state->initializer)) {
            return LazyObjectState::STATUS_INITIALIZED_FULL === $state->status;
        }

        $class = $this::class;
        $properties = (array) $this;
        $propertyScopes = Hydrator::$propertyScopes[$class] ??= Hydrator::getPropertyScopes($class);
        foreach ($state->initializer as $key => $initializer) {
            if (!\array_key_exists($key, $properties) && isset($propertyScopes[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Forces initialization of a lazy object and returns it.
     */
    public function initializeLazyObject(): static
    {
        if (!$state = Registry::$states[$this->lazyObjectId ?? ''] ?? null) {
            return $this;
        }

        if (!\is_array($state->initializer)) {
            if (LazyObjectState::STATUS_UNINITIALIZED_FULL === $state->status) {
                $state->initialize($this, '', null);
            }

            return $this;
        }

        $class = $this::class;
        $properties = (array) $this;
        $propertyScopes = Hydrator::$propertyScopes[$class] ??= Hydrator::getPropertyScopes($class);
        foreach ($state->initializer as $key => $initializer) {
            if (\array_key_exists($key, $properties) || ![$scope, $name, $readonlyScope] = $propertyScopes[$key] ?? null) {
                continue;
            }

            $state->initialize($this, $name, $readonlyScope ?? ('*' !== $scope ? $scope : null));
            $properties = (array) $this;
        }

        return $this;
    }

    /**
     * @return bool Returns false when the object cannot be reset, ie when it's not a lazy object
     */
    public function resetLazyObject(): bool
    {
        if (!$state = Registry::$states[$this->lazyObjectId ?? ''] ?? null) {
            return false;
        }

        if (LazyObjectState::STATUS_UNINITIALIZED_FULL !== $state->status) {
            $state->reset($this);
        }

        return true;
    }

    public function &__get($name): mixed
    {
        $propertyScopes = Hydrator::$propertyScopes[$this::class] ??= Hydrator::getPropertyScopes($this::class);
        $scope = null;

        if ([$class, , $readonlyScope] = $propertyScopes[$name] ?? null) {
            $scope = Registry::getScope($propertyScopes, $class, $name);
            $state = Registry::$states[$this->lazyObjectId ?? ''] ?? null;

            if ($state && (null === $scope || isset($propertyScopes["\0$scope\0$name"]))
                && LazyObjectState::STATUS_UNINITIALIZED_PARTIAL !== $state->initialize($this, $name, $readonlyScope ?? $scope)
            ) {
                goto get_in_scope;
            }
        }

        if ($parent = (Registry::$parentMethods[self::class] ??= Registry::getParentMethods(self::class))['get']) {
            if (2 === $parent) {
                return parent::__get($name);
            }
            $value = parent::__get($name);

            return $value;
        }

        if (null === $class) {
            $frame = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
            trigger_error(sprintf('Undefined property: %s::$%s in %s on line %s', $this::class, $name, $frame['file'], $frame['line']), \E_USER_NOTICE);
        }

        get_in_scope:

        if (null === $scope) {
            if (null === $readonlyScope) {
                return $this->$name;
            }
            $value = $this->$name;

            return $value;
        }
        $accessor = Registry::$classAccessors[$scope] ??= Registry::getClassAccessors($scope);

        return $accessor['get']($this, $name, null !== $readonlyScope);
    }

    public function __set($name, $value): void
    {
        $propertyScopes = Hydrator::$propertyScopes[$this::class] ??= Hydrator::getPropertyScopes($this::class);
        $scope = null;
        $state = null;

        if ([$class, , $readonlyScope] = $propertyScopes[$name] ?? null) {
            $scope = Registry::getScope($propertyScopes, $class, $name, $readonlyScope);
            $state = Registry::$states[$this->lazyObjectId ?? ''] ?? null;

            if ($state && ($readonlyScope === $scope || isset($propertyScopes["\0$scope\0$name"]))) {
                if (LazyObjectState::STATUS_UNINITIALIZED_FULL === $state->status) {
                    $state->initialize($this, $name, $readonlyScope ?? $scope);
                }
                goto set_in_scope;
            }
        }

        if ((Registry::$parentMethods[self::class] ??= Registry::getParentMethods(self::class))['set']) {
            parent::__set($name, $value);

            return;
        }

        set_in_scope:

        if (null === $scope) {
            $this->$name = $value;
        } else {
            $accessor = Registry::$classAccessors[$scope] ??= Registry::getClassAccessors($scope);
            $accessor['set']($this, $name, $value);
        }
    }

    public function __isset($name): bool
    {
        $propertyScopes = Hydrator::$propertyScopes[$this::class] ??= Hydrator::getPropertyScopes($this::class);
        $scope = null;

        if ([$class, , $readonlyScope] = $propertyScopes[$name] ?? null) {
            $scope = Registry::getScope($propertyScopes, $class, $name);
            $state = Registry::$states[$this->lazyObjectId ?? ''] ?? null;

            if ($state && (null === $scope || isset($propertyScopes["\0$scope\0$name"]))
                && LazyObjectState::STATUS_UNINITIALIZED_PARTIAL !== $state->initialize($this, $name, $readonlyScope ?? $scope)
            ) {
                goto isset_in_scope;
            }
        }

        if ((Registry::$parentMethods[self::class] ??= Registry::getParentMethods(self::class))['isset']) {
            return parent::__isset($name);
        }

        isset_in_scope:

        if (null === $scope) {
            return isset($this->$name);
        }
        $accessor = Registry::$classAccessors[$scope] ??= Registry::getClassAccessors($scope);

        return $accessor['isset']($this, $name);
    }

    public function __unset($name): void
    {
        $propertyScopes = Hydrator::$propertyScopes[$this::class] ??= Hydrator::getPropertyScopes($this::class);
        $scope = null;

        if ([$class, , $readonlyScope] = $propertyScopes[$name] ?? null) {
            $scope = Registry::getScope($propertyScopes, $class, $name, $readonlyScope);
            $state = Registry::$states[$this->lazyObjectId ?? ''] ?? null;

            if ($state && ($readonlyScope === $scope || isset($propertyScopes["\0$scope\0$name"]))) {
                if (LazyObjectState::STATUS_UNINITIALIZED_FULL === $state->status) {
                    $state->initialize($this, $name, $readonlyScope ?? $scope);
                }
                goto unset_in_scope;
            }
        }

        if ((Registry::$parentMethods[self::class] ??= Registry::getParentMethods(self::class))['unset']) {
            parent::__unset($name);

            return;
        }

        unset_in_scope:

        if (null === $scope) {
            unset($this->$name);
        } else {
            $accessor = Registry::$classAccessors[$scope] ??= Registry::getClassAccessors($scope);
            $accessor['unset']($this, $name);
        }
    }

    public function __clone(): void
    {
        if ($state = Registry::$states[$this->lazyObjectId ?? ''] ?? null) {
            Registry::$states[$this->lazyObjectId = spl_object_id($this)] = clone $state;
        }

        if ((Registry::$parentMethods[self::class] ??= Registry::getParentMethods(self::class))['clone']) {
            parent::__clone();
        }
    }

    public function __serialize(): array
    {
        $class = self::class;

        if ((Registry::$parentMethods[$class] ??= Registry::getParentMethods($class))['serialize']) {
            $properties = parent::__serialize();
        } else {
            $this->initializeLazyObject();
            $properties = (array) $this;
        }
        unset($properties["\0$class\0lazyObjectId"]);

        if (Registry::$parentMethods[$class]['serialize'] || !Registry::$parentMethods[$class]['sleep']) {
            return $properties;
        }

        $scope = get_parent_class($class);
        $data = [];

        foreach (parent::__sleep() as $name) {
            $value = $properties[$k = $name] ?? $properties[$k = "\0*\0$name"] ?? $properties[$k = "\0$scope\0$name"] ?? $k = null;

            if (null === $k) {
                trigger_error(sprintf('serialize(): "%s" returned as member variable from __sleep() but does not exist', $name), \E_USER_NOTICE);
            } else {
                $data[$k] = $value;
            }
        }

        return $data;
    }

    public function __destruct()
    {
        $state = Registry::$states[$this->lazyObjectId ?? ''] ?? null;

        try {
            if ($state && \in_array($state->status, [LazyObjectState::STATUS_UNINITIALIZED_FULL, LazyObjectState::STATUS_UNINITIALIZED_PARTIAL], true)) {
                return;
            }

            if ((Registry::$parentMethods[self::class] ??= Registry::getParentMethods(self::class))['destruct']) {
                parent::__destruct();
            }
        } finally {
            if ($state) {
                unset(Registry::$states[$this->lazyObjectId]);
            }
        }
    }

    private function setLazyObjectAsInitialized(bool $initialized): void
    {
        $state = Registry::$states[$this->lazyObjectId ?? ''];

        if ($state && !\is_array($state->initializer)) {
            $state->status = $initialized ? LazyObjectState::STATUS_INITIALIZED_FULL : LazyObjectState::STATUS_UNINITIALIZED_FULL;
        }
    }
}
