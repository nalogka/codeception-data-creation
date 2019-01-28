<?php

namespace Nalogka\Codeception\Database;

use Codeception\Module\Doctrine2;
use Codeception\TestInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\PropertyInfo\DoctrineExtractor;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Type;

/**
 * Модуль создания и проверки данных для проектов,
 * использующих Doctrine2
 */
class DataCreation extends Doctrine2
{
    private $recentlyCreated = [];
    private $previouslyCreated = [];

    private $creators = [];
    private $normalizeTypeNameMap = [];
    private $dataClasses = [];

    public function _initialize()
    {
        parent::_initialize();

        foreach ($this->getModules() as $module) {
            if ($module instanceof DataCreatorModuleInterface) {
                $typeNames = $module->getNameVariants();
                $normalTypeName = reset($typeNames);
                $this->creators[$normalTypeName] = $module->getDataCreator();
                $this->dataClasses[$normalTypeName] = $module->getDataClass();
                foreach ($typeNames as $typeName) {
                    $this->normalizeTypeNameMap[$typeName] = $normalTypeName;
                }
            }
        }
    }

    public function _after(TestInterface $test)
    {
        $this->recentlyCreated = [];
        $this->previouslyCreated = [];

        return parent::_after($test);
    }

    /**
     * Получение ранее сохраненных данных или создание новых, по данному идентификатору
     *
     * @param string $type       Тип создаваемых данных.
     * @param string $id         Идентификатор данных, по которому к ним идет обращение в тексте тестового сценария.
     * @param array  ...$params  второй и последующие параметры для метода создания данных
     *
     * @return mixed
     */
    public function getOrCreate($type, $id, ...$params)
    {
        if (!isset($this->creators[$type])) {
            throw new \RuntimeException('Не найден метод создания объекта типа "' . $type . '"');
        }

        if ($id && !$this->hasPreviouslyCreated($type, $id) || !$id && !$this->hasRecentlyCreated($type)) {
            array_unshift($params, $id);
            call_user_func_array($this->creators[$type], $params);
        }

        return !$id ? $this->getRecentlyCreated($type) : $this->getPreviouslyCreated($type, $id);
    }

    /**
     * Сохранение созданных данных с одновременной регистрацией
     *
     * @param string $types   Тип созданных данных или множество типов, указанных через запятую.
     *                        При указании множества типов, первым должен быть указан конкретный тип, а далее абстрактные.
     * @param string $id      Идентификатор данных, по которому к ним идет обращение в тексте тестового сценария.
     * @param object $entity  Сущность для сохранения
     * @param array  $data    Данные сущности (значения свойств, устанавливаемые перед сохранением)
     */
    public function persistAndRegisterCreated($types, $id, $entity, array $data = [])
    {
        $this->persistEntity($entity, $data);
        $this->registerPreviouslyCreated($types, $id, $entity);
    }

    /**
     * Регистрация созданных данных
     *
     * @param string $types Тип созданных данных или множество типов, указанных через запятую.
     *                      При указании множества типов, первым должен быть указан конкретный тип, а далее абстрактные.
     * @param string $id    Идентификатор данных, по которому к ним идет обращение в тексте тестового сценария.
     * @param mixed  $data  Сами данные.
     *
     * @return mixed Зарегистрированные данные
     * @throws \RuntimeException Если данные такого типа с таким ID ранее были застрированны
     */
    public function registerPreviouslyCreated($types, $id, $data)
    {
        $types = array_map('trim', explode(',', $types));

        foreach ($types as $type) {
            if (isset($this->previouslyCreated[$type][$id])) {
                throw new \RuntimeException('Попытка зарегистрировать созданные данные с ранее зарегистрированным ID');
            }

            $this->previouslyCreated[$type][$id] = $data;
            $this->recentlyCreated[$type] = $data;
        }

        return $data;
    }

    /**
     * Проверка наличие ранее созданных данных по идентификатору
     * @param string $type Тип требуемых данных
     * @param string $id   Идентификатор данных, по которому к ним идет обращение в тексте тестового сценария.
     *
     * @return boolean
     */
    public function hasPreviouslyCreated($type, $id)
    {
        return isset($this->previouslyCreated[$type][$id]);
    }

    /**
     * Получение ранее созданных данных по идентификатору
     * @param string $type Тип требуемых данных
     * @param string $id   Идентификатор данных, по которому к ним идет обращение в тексте тестового сценария.
     *
     * @return mixed
     * @throws \RuntimeException Если запрошенные данные отсутствуют в реестре ранее созданных данных
     */
    public function getPreviouslyCreated($type, $id)
    {
        if (!$this->hasPreviouslyCreated($type, $id)) {
            throw new \RuntimeException('Запрошены ранее зарегистрированные данные с неизвестным ID');
        }

        return $this->previouslyCreated[$type][$id];
    }

    /**
     * Были ли недавно созданы данные указанного типа.
     *
     * @param string $type Тип требуемых данных
     *
     * @return boolean
     */
    public function hasRecentlyCreated($type)
    {
        return isset($this->recentlyCreated[$type]);
    }

    /**
     * Получение последних созданных данных указанного типа.
     *
     * @param string $type Тип требуемых данных
     *
     * @return mixed
     * @throws \RuntimeException Если в реестре созданных данных ранее не было зарегистрированно никаких данных
     */
    public function getRecentlyCreated($type)
    {
        if (!$this->hasRecentlyCreated($type)) {
            throw new \RuntimeException('Запрошены предыдущие созданные данные, но таких данных не было зарегистрировано');
        }

        return $this->recentlyCreated[$type];
    }

    /**
     * Заменяет в строке подстановку(ки), вида `{id персоны "Иван"}` на заначение
     * поля ранее созданных данных
     *
     * @param string $string строка с подстановками
     *
     * @return string
     */
    public function fillDataPlaceholders($string)
    {
        $calculateReplacement = function ($matches) {
            array_shift($matches);
            list($field, $dataType, $id) = $matches;

            return PropertyAccess::createPropertyAccessor()->getValue(
                $this->getPreviouslyCreated($this->getNormalizedTypeName($dataType), $id),
                $field
            );
        };

        return preg_replace_callback('/\{(\w+)\s+([^}]+)\s+"([^}"]+)"\}/', $calculateReplacement, $string);
    }


    public function seeItemInRepository($type, array $params)
    {
        $this->seeInRepository($this->dataClasses[$this->getNormalizedTypeName($type)], $params);
    }


    public function dontSeeItemInRepository($type, array $params)
    {
        $this->dontSeeInRepository($this->dataClasses[$this->getNormalizedTypeName($type)], $params);
    }

    protected function proceedSeeInRepository($entity, $params = [])
    {
        /** @var QueryBuilder $qb */
        $qb = $this->em->getRepository($entity)->createQueryBuilder('s');
        $this->buildAssociationQuery($qb, $entity, 's', $params);
        $this->debug($qb->getDQL());
        $res = $qb->getQuery()->setCacheable(false)->getArrayResult();

        return ['True', (count($res) > 0), "$entity with " . json_encode($params)];
    }
    
    /**
     * It's Hugging Recursive!
     *
     * @param QueryBuilder $qb
     * @param $assoc
     * @param $alias
     * @param $params
     */
    protected function buildAssociationQuery($qb, $assoc, $alias, $params)
    {
        $classMetadata = $this->em->getClassMetadata($assoc);
        $typeExtractor = new DoctrineExtractor($this->em);

        foreach ($params as $key => $val) {
            $paramname = str_replace(".", "", "{$alias}_{$key}");
            if (isset($classMetadata->associationMappings)) {
                if (array_key_exists($key, $classMetadata->associationMappings)) {
                    if (is_array($val)) {
                        $qb->innerJoin("$alias.$key", $paramname);
                        $this->buildAssociationQuery(
                            $qb,
                            $classMetadata->associationMappings[$key]['targetEntity'],
                            $paramname,
                            $val
                        );

                        continue;
                    }
                }
            }
            if ($val === null) {
                $qb->andWhere("$alias.$key IS NULL");
            } else {
                $qb->andWhere("$alias.$key = :$paramname");

                $isCustomType = $typeExtractor->getTypes($assoc, $key) === null;

                // В случае если поле имеет нестандартный тип данных, нужно передать его в QueryBuilder->setParameter(),
                // чтобы использовалась функция конвертации значения этого поля,
                // и фильрация выполнялась по значению правильного типа (тип данных в БД)
                $qb->setParameter($paramname, $val, $isCustomType ? $classMetadata->getTypeOfField($key) : null);
            }
        }
    }

    private function getNormalizedTypeName($dataType)
    {
        if (!isset($this->normalizeTypeNameMap[$dataType])) {
            throw new \RuntimeException(
                'Попытка нормализации неизвестного типа "' . $dataType
                    . '". Возможно не указан такой алиас в модуле-хэлпере создания таких данных.'
            );
        }

        return $this->normalizeTypeNameMap[$dataType];
    }
}
