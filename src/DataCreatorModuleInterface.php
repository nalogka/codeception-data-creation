<?php

namespace Nalogka\Codeception\Database;

/**
 * Модуль-создатель данных для теста
 */
interface DataCreatorModuleInterface
{
    /**
     * Определяет метод-создатель данных.
     *
     * @return callable первым аргументом которого передается строк-идентификатор
     *               а остальные параметры специфичные для каждого типа данных.
     */
    public function getDataCreator();

    /**
     * Определяет варианты написания имени типа (в падежах и числах, используемых в шагах тестов).
     *
     * @return array
     */
    public function getNameVariants();

    /**
     * Определение имени класса данных.
     *
     * @return string
     */
    public function getDataClass();
}
