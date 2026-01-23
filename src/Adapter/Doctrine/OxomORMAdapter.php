<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Adapter\Doctrine;

use Omines\DataTablesBundle\Adapter\AdapterQuery;
use Omines\DataTablesBundle\Adapter\Doctrine\Event\ORMAdapterQueryEvent;
use Omines\DataTablesBundle\Adapter\Doctrine\ORM\AutomaticQueryBuilder;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\Column\NumberColumn;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Traversable;

/**
 * Optimized version of ORMAdapter
 *
 * @author Jan Böhmer
 */
class OxomORMAdapter extends ORMAdapter
{

    protected function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        //Enforce object hydration mode (fetch join only works for objects)
        $resolver->addAllowedValues('hydrate', AbstractQuery::HYDRATE_OBJECT);
    }

    protected function prepareQuery(AdapterQuery $query): void
    {
        $state = $query->getState();
        $query->set('qb', $builder = $this->createQueryBuilder($state));
        $query->set('rootAlias', $rootAlias = $builder->getDQLPart('from')[0]->getAlias());

        // Provide default field mappings if needed
        foreach ($state->getDataTable()->getColumns() as $column) {
            if (null === $column->getField() && isset($this->metadata->fieldMappings[$name = $column->getName()])) {
                $column->setOption('field', "{$rootAlias}.{$name}");
            }
        }

        /** @var Query\Expr\From $fromClause */
        $fromClause = $builder->getDQLPart('from')[0];
        $identifier = "{$fromClause->getAlias()}.{$this->metadata->getSingleIdentifierFieldName()}";

        $query->setTotalRows(0);

        // Get record count after filtering
        $this->buildCriteria($builder, $state);
        $query->setFilteredRows($this->getCount($builder, $identifier));

        if ($state->getShowSummary()) {
            dump('showSummary');

            //
            // Complex but working better
            //

            // Sub request for ids
            $idBuilder = clone $builder;
            $idBuilder->select("DISTINCT({$rootAlias}.id)");
            $subDQL = $idBuilder->getQuery()->getDQL();

            // Top request for summary
            $repo = $this->manager->getRepository($builder->getDQLPart('from')[0]->getFrom());
            $summaryBuilder = $repo->createQueryBuilder('top');
            $summaryBuilder
                ->select('1 as useless')
            ;
            $automaticQueryBuilder = new AutomaticQueryBuilder($this->manager, $this->metadata,'summary');
            $automaticQueryBuilder->setEntityShortName('top');
            foreach ($state->getDataTable()->getColumns() as $column) {
                if ($column instanceof NumberColumn && $column->isVisible() && $column->getName() != 'selector') {
                    if (str_starts_with($column->getOrderField(), $rootAlias.'.')) {
                        $summaryBuilder->addSelect('SUM(' . str_replace($rootAlias.'.', 'top.', $column->getOrderField()) . ') as SUM_' . $column->getName());
                        $summaryBuilder->addSelect('AVG(' . str_replace($rootAlias.'.', 'top.', $column->getOrderField()) . ') as AVG_' . $column->getName());
                    }
                    else {
                        $summaryBuilder->addSelect('SUM(summary_' . $column->getOrderField() . ') as SUM_' . $column->getName());
                        $summaryBuilder->addSelect('AVG(summary_' . $column->getOrderField() . ') as AVG_' . $column->getName());
                    }
                    $automaticQueryBuilder->addJoinColumns($column,  str_replace($rootAlias.'.', 'top.', $column->getField()));
                }
            }
            $summaryBuilder->where($summaryBuilder->expr()->in('top.id', $subDQL));
            foreach ($idBuilder->getParameters() as $parameter) {
                $summaryBuilder->setParameter($parameter->getName(), $parameter->getValue());
            }

            // Apply automatic joins
            foreach ($automaticQueryBuilder->getJoins() as $key => $value) {
                $summaryBuilder->{$value['type']}($key, $value['alias']);
            }

            // Fast but not working with mutiples joins
            /*
            $summaryBuilder = clone $builder;
            $summaryBuilder->select('1 as useless');
            foreach ($state->getDataTable()->getColumns() as $column) {
                if ($column instanceof NumberColumn && $column->isVisible() && $column->getName() != 'selector') {
                    $summaryBuilder->addSelect('SUM(' . $column->getOrderField() . ') as SUM_' . $column->getName());
                    $summaryBuilder->addSelect('AVG(' . $column->getOrderField() . ') as AVG_' . $column->getName());
                }
            }
            */

            $results = $summaryBuilder->getQuery()->getResult();

            $result = [];
            if (isset($results[0])) {
                $result = $results[0];
            }
            unset($result['useless']);
            $summary = [];
            foreach ($result as $alias => $value) {
                list($metric, $columnName) = explode('_', $alias);
                $column = $state->getDataTable()->getColumnByName($columnName);
                $summary[$columnName][$metric] = $column->renderSummary($value);
            }
            $query->setTotalSummary($summary);
        }

        // Perform mapping of all referred fields and implied fields
        $aliases = $this->getAliases($query);
        $query->set('aliases', $aliases);
        $query->setIdentifierPropertyPath($this->mapFieldToPropertyPath($identifier, $aliases));
    }

    public function getResults(AdapterQuery $query): Traversable
    {
        $builder = $query->get('qb');
        /* @var $builder QueryBuilder */
        $state = $query->getState();

        // Apply definitive view state for current 'page' of the table
        foreach ($state->getOrderBy() as list($column, $direction)) {
            /** @var AbstractColumn $column */
            if ($column->isOrderable()) {
                $builder->addOrderBy($column->getOrderField(), $direction);
            }
        }
        if (null !== $state->getLength()) {
            $builder
                ->setFirstResult($state->getStart())
                ->setMaxResults($state->getLength());
        }

        $query = $builder->getQuery();
        $event = new ORMAdapterQueryEvent($query);
        $state->getDataTable()->getEventDispatcher()->dispatch($event, ORMAdapterEvents::PRE_QUERY);

        // Fetch one by one
        $repository =  $this->manager->getRepository($builder->getDQLPart('from')[0]->getFrom());
        $ids = $this->getIds($builder);
        foreach ($ids as $id) {
            if (method_exists($state->getDataTable()->getType(), 'fetchById')) {
                $entity = $state->getDataTable()->getType()->fetchById($repository, $id);
            } else {
                $entity = $repository->findOneBy(['id' => $id]);
            }
            yield $entity;
            $this->manager->detach($entity);
        }
    }

    //
    // Paginator versions
    //

    /*
    public function getCount(QueryBuilder $queryBuilder, $identifier)
    {
        $count = 0;
        try {
            $paginator = new Paginator($queryBuilder);
            $count = $paginator->count();
        } catch (Exception $e) {
            if ($_ENV['APP_ENV'] === 'dev') {
                dump($e);
            }
        }
        return $count;
    }

    public function getIds(QueryBuilder $queryBuilder): array
    {
        $ids = [];
        try {
            $query = $queryBuilder->getQuery();
            $query->setHydrationMode(AbstractQuery::HYDRATE_ARRAY);
            $paginator = new Paginator($query);
            foreach ($paginator as $result) {
                $ids[$result['id']] = true;
            }
        } catch (Exception $e) {
            if ($_ENV['APP_ENV'] === 'dev') {
                dump($e);
            }
        }
        return array_keys($ids);
    }
    */

    //
    // Simple and fast versions
    //

    public function getCount(QueryBuilder $queryBuilder, $identifier): int
    {
        try {
            $qb = clone $queryBuilder;
            $qb->resetDQLPart('orderBy');
            $qb->select($qb->expr()->countDistinct($qb->getRootAliases()[0]));
            return (int) $qb->getQuery()->getSingleScalarResult();
        } catch (Exception $e) {
            if ($_ENV['APP_ENV'] === 'dev') {
                dump($e);
            }
        }
        return 0;
    }

    public function getIds(QueryBuilder $queryBuilder): array
    {
        $ids = [];
        try {
            $qb = clone $queryBuilder;
            $qb->select('DISTINCT('.$qb->getRootAliases()[0].'.id) AS id');
            $query = $qb->getQuery();
            foreach ($query->getResult(AbstractQuery::HYDRATE_ARRAY) as $result) {
                $ids[$result['id']] = true;
            }
        } catch (Exception $e) {
            if ($_ENV['APP_ENV'] === 'dev') {
                dump($e);
            }
        }
        return array_keys($ids);
    }

}
