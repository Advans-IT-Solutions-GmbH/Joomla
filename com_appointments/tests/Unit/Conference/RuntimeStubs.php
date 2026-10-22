<?php

namespace Joomla\Database;

// Only the API exercised here is needed when Joomla is absent from the unit environment.
if (!interface_exists(DatabaseInterface::class)) {
    interface DatabaseInterface
    {
        public function quoteName($name);
        public function quote($text, $escape = true);
        public function setQuery($query);
        public function execute();
        public function loadResult();
        public function loadAssoc();
        public function updateObject($table, &$object, $key, $nulls = false);
        public function transactionStart();
        public function transactionCommit();
        public function transactionRollback();
    }
}

namespace Joomla\Registry;

if (!class_exists(Registry::class)) {
    class Registry
    {
        public function __construct(private array $values = [])
        {
        }

        public function get($key, $default = null)
        {
            return $this->values[$key] ?? $default;
        }
    }
}

namespace Joomla\CMS\Form;

if (!class_exists(FormRule::class)) {
    class FormRule
    {
    }
}
