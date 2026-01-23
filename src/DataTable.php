<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle;

use App\Oxom\DatatableBundle\Datatable\Type\BaseDatatableType;
use App\Oxom\DatatableBundle\Entity\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\Adapter\AdapterInterface;
use Omines\DataTablesBundle\Adapter\ResultSetInterface;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DependencyInjection\Instantiator;
use Omines\DataTablesBundle\Event\DataTablePostResponseEvent;
use Omines\DataTablesBundle\Event\DataTablePreResponseEvent;
use Omines\DataTablesBundle\Exception\InvalidArgumentException;
use Omines\DataTablesBundle\Exception\InvalidConfigurationException;
use Omines\DataTablesBundle\Exception\InvalidStateException;
use Omines\DataTablesBundle\Exporter\DataTableExporterManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * DataTable.
 *
 * @author Robbert Beesems <robbert.beesems@omines.com>
 */
class DataTable
{
    public const DEFAULT_OPTIONS = [
        'jQueryUI' => false,
        'pagingType' => 'full_numbers',
        'lengthMenu' => [[10, 25, 50, -1], [10, 25, 50, 'All']],
        'pageLength' => 10,
        'displayStart' => 0,
        'serverSide' => true,
        'processing' => true,
        'paging' => true,
        'lengthChange' => true,
        'ordering' => true,
        'searching' => false,
        'search' => null,
        'autoWidth' => false,
        'order' => [],
        'searchDelay' => 400,
        'dom' => 'lftrip',
        'orderCellsTop' => true,
        'stateSave' => false,
        'fixedHeader' => false,
    ];

    public const DEFAULT_TEMPLATE = '@DataTables/datatable_html.html.twig';
    public const SORT_ASCENDING = 'asc';
    public const SORT_DESCENDING = 'desc';
    public const SORT_OPTIONS = [self::SORT_ASCENDING, self::SORT_DESCENDING];

    protected ?AdapterInterface $adapter = null;

    /** @var AbstractColumn[] */
    protected array $columns = [];

    /** @var array<string, AbstractColumn> */
    protected array $columnsByName = [];
    protected EventDispatcherInterface $eventDispatcher;
    protected DataTableExporterManager $exporterManager;
    protected string $method = Request::METHOD_POST;

    /** @var array<string, mixed> */
    protected array $options;
    protected bool $languageFromCDN = true;
    protected string $name = 'dt';
    protected string $persistState = 'fragment';
    protected string $template = self::DEFAULT_TEMPLATE;

    /** @var array<string, mixed> */
    protected array $templateParams = [];

    /** @var callable */
    protected $transformer;

    protected string $translationDomain = 'messages';

    private DataTableRendererInterface $renderer;
    private ?DataTableState $state = null;
    private Instantiator $instantiator;

    /*************************************** seb-sans *****************************************************************/

    private \App\Oxom\DatatableBundle\Entity\Datatable $oxomDatatable;
    private Configuration $configuration;
    protected ?DataTableTypeInterface $type = null;
    private array $batchActions = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, DataTableExporterManager $exporterManager, array $options = [], ?Instantiator $instantiator = null)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->exporterManager = $exporterManager;

        $this->instantiator = $instantiator ?? new Instantiator();

        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->options = $resolver->resolve($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function add(string $name, string $type, array $options = []): static
    {
        // Ensure name is unique
        if (isset($this->columnsByName[$name])) {
            throw new InvalidArgumentException(sprintf("There already is a column with name '%s'", $name));
        }

        $column = $this->instantiator->getColumn($type);
        $column->initialize($name, count($this->columns), $options, $this);

        $this->columns[] = $column;
        $this->columnsByName[$name] = $column;

        return $this;
    }

    public function remove(string $name): static
    {
        if (!isset($this->columnsByName[$name])) {
            throw new InvalidArgumentException(sprintf("There is no column with name '%s'", $name));
        }

        $column = $this->columnsByName[$name];
        unset($this->columnsByName[$name]);
        $index = array_search($column, $this->columns, true);
        unset($this->columns[$index]);

        return $this;
    }

    public function clearColumns(): static
    {
        $this->columns = [];
        $this->columnsByName = [];

        return $this;
    }

    /**
     * Adds an event listener to an event on this DataTable.
     *
     * @param string   $eventName The name of the event to listen to
     * @param callable $listener  The listener to execute
     * @param int      $priority  The priority of the listener. Listeners
     *                            with a higher priority are called before
     *                            listeners with a lower priority.
     *
     * @return $this
     */
    public function addEventListener(string $eventName, callable $listener, int $priority = 0): static
    {
        $this->eventDispatcher->addListener($eventName, $listener, $priority);

        return $this;
    }

    /**
     * @param int|string|AbstractColumn $column
     * @return $this
     */
    public function addOrderBy($column, string $direction = self::SORT_ASCENDING)
    {
        if (!$column instanceof AbstractColumn) {
            $column = is_int($column) ? $this->getColumn($column) : $this->getColumnByName((string) $column);
        }
        $direction = mb_strtolower($direction);
        if (!in_array($direction, self::SORT_OPTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Sort direction must be one of %s', implode(', ', self::SORT_OPTIONS)));
        }
        $this->options['order'][] = [$column->getIndex(), $direction];

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createAdapter(string $adapter, array $options = []): static
    {
        return $this->setAdapter($this->instantiator->getAdapter($adapter), $options);
    }

    public function getAdapter(): AdapterInterface
    {
        return $this->adapter ?? throw new InvalidConfigurationException('DataTable has no adapter');
    }

    public function getColumn(int $index): AbstractColumn
    {
        if ($index < 0 || $index >= count($this->columns)) {
            throw new InvalidArgumentException(sprintf('There is no column with index %d', $index));
        }

        return $this->columns[$index];
    }

    public function getColumnByName(string $name): AbstractColumn
    {
        if (!isset($this->columnsByName[$name])) {
            throw new InvalidArgumentException(sprintf("There is no column named '%s'", $name));
        }

        return $this->columnsByName[$name];
    }

    /**
     * @return AbstractColumn[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function isLanguageFromCDN(): bool
    {
        return $this->languageFromCDN;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPersistState(): string
    {
        return $this->persistState;
    }

    public function getState(): DataTableState
    {
        return $this->state ?? throw new InvalidStateException('The DataTable does not know its state yet, did you call handleRequest?');
    }

    public function hasState(): bool
    {
        return null !== $this->state;
    }

    public function getTranslationDomain(): string
    {
        return $this->translationDomain;
    }

    public function isCallback(): bool
    {
        return null !== $this->state && $this->state->isCallback();
    }

    public function handleRequest(Request $request): static
    {
        $parameters = match ($this->getMethod()) {
            Request::METHOD_GET => $request->query,
            Request::METHOD_POST => $request->request,
            default => throw new InvalidConfigurationException(sprintf("Unknown request method '%s'", $this->getMethod())),
        };
        if ($this->getName() === $parameters->get('_dt')) {
            if (null === $this->state) {
                $this->state = DataTableState::fromDefaults($this);
            }
            $this->state->applyParameters($parameters);
        }

        return $this;
    }

    public function getResponse(): Response
    {
        $this->eventDispatcher->dispatch(new DataTablePreResponseEvent($this), DataTableEvents::PRE_RESPONSE);

        $state = $this->getState();

        if ($this->state->getBatchAction()) {
            $batchAction = $this->getBatchActions()[$this->state->getBatchAction()];
            $ids = [];
            if (isset($batchAction['withoutSelection']) && $batchAction['withoutSelection']) {
                // keep ids empty
            } else {
                $ids = $this->state->getBatchIds();
                if (!$ids) {
                    $builder = $this->getAdapter()->createQueryBuilder($this->state);
                    $this->getAdapter()->buildCriteria($builder, $this->state);
                    $ids = $this->getAdapter()->getIds($builder);
                }
            }
            return $batchAction['callback']($ids, $this->state->getBatchActionPrompt());
        }

        // Server side export
        if (null !== $state->getExporterName()) {
            $response = $this->exporterManager
                ->setDataTable($this)
                ->setExporterName($state->getExporterName())
                ->getResponse();
            $this->eventDispatcher->dispatch(new DataTablePostResponseEvent($this), DataTableEvents::POST_RESPONSE);

            return $response;
        }

        $resultSet = $this->getResultSet();
        $response = [
            'draw' => $state->getDraw(),
            'recordsTotal' => $resultSet->getTotalRecords(),
            'recordsSummary' => $resultSet->getTotalSummary(),
            'recordsFiltered' => $resultSet->getTotalDisplayRecords(),
            'data' => iterator_to_array($resultSet->getData()),
            'error' => $resultSet->getError(),
        ];
        if ($state->isInitial()) {
            $response['options'] = $this->getInitialResponse();
            $response['template'] = $this->renderer->renderDataTable($this, $this->template, $this->templateParams);
        }

        $this->eventDispatcher->dispatch(new DataTablePostResponseEvent($this), DataTableEvents::POST_RESPONSE);

        // Avoid "Malformed UTF-8 characters, possibly incorrectly encoded"
        foreach ($response['data'] as $key => $value) {
            $response['data'][$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return new JsonResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getInitialResponse(): array
    {
        $options = array_merge($this->getOptions(), [
            'columns' => array_map(
                function (AbstractColumn $column) {
                    return [
                        'data' => $column->getName(),
                        'orderable' => $column->isOrderable(),
                        'searchable' => $column->isSearchable(),
                        'visible' => $column->isVisible(),
                        'className' => $column->getClassName(),
                    ];
                }, $this->getColumns()
            ),
        ]);

        //
        // Search
        //
        $options['search'] = [
            'search' => $this->getConfiguration()->getSearch(),
            'regexp' => true
        ];

        //
        // colReorder
        //
        $order = [];
        if (isset($this->columnsByName[BaseDatatableType::COLUMN_IDENTIFIER_SELECTOR])) {
            $order[] = $this->columnsByName[BaseDatatableType::COLUMN_IDENTIFIER_SELECTOR]->getIndex();
        }
        foreach ($this->getConfiguration()->getColumns() as $columnIdentifier) {
            if (isset($this->columnsByName[$columnIdentifier])) {
                $order[] = $this->columnsByName[$columnIdentifier]->getIndex();
            }
        }
        foreach ($this->columns as $idx => $column) {
            if (!in_array($idx, $order)) {
                $order[] = $idx;
            }
        }
        $this->getConfiguration()->getColumns();
        $options['colReorder'] = [
            'realtime' => false,
            'fixedColumnsLeft' => 1,
            'order' => $order
        ];
        return $options;
    }

    protected function getResultSet(): ResultSetInterface
    {
        if (null === $this->adapter) {
            throw new InvalidStateException('No adapter was configured yet to retrieve data with. Call "createAdapter" or "setAdapter" before attempting to return data');
        }

        return $this->adapter->getData($this->getState());
    }

    public function getTransformer(): ?callable
    {
        return $this->transformer;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    /**
     * @param ?array<string, mixed> $options
     */
    public function setAdapter(AdapterInterface $adapter, ?array $options = null): static
    {
        if (null !== $options) {
            $adapter->configure($options);
        }
        $this->adapter = $adapter;

        return $this;
    }

    public function setLanguageFromCDN(bool $languageFromCDN): static
    {
        $this->languageFromCDN = $languageFromCDN;

        return $this;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function setPersistState(string $persistState): static
    {
        $this->persistState = $persistState;

        return $this;
    }

    public function setRenderer(DataTableRendererInterface $renderer): static
    {
        $this->renderer = $renderer;

        return $this;
    }

    public function setName(string $name): static
    {
        if (empty($name)) {
            throw new InvalidArgumentException('DataTable name cannot be empty');
        }
        $this->name = $name;

        return $this;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function setTemplate(string $template, array $parameters = []): static
    {
        $this->template = $template;
        $this->templateParams = $parameters;

        return $this;
    }

    public function setTranslationDomain(string $translationDomain): static
    {
        $this->translationDomain = $translationDomain;

        return $this;
    }

    /**
     * @return $this
     */
    public function setTransformer(callable $formatter)
    {
        $this->transformer = $formatter;

        return $this;
    }

    /**
     * @return $this
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(self::DEFAULT_OPTIONS);

        return $this;
    }

    /*************************************** seb-sans *****************************************************************/

    public function getOxomDatatable(): \App\Oxom\DatatableBundle\Entity\Datatable
    {
        return $this->oxomDatatable;
    }

    public function setOxomDatatable(\App\Oxom\DatatableBundle\Entity\Datatable $datatable): static
    {
        $this->oxomDatatable = $datatable;
        return $this;
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function setConfiguration(Configuration $configuration): static
    {
        $this->configuration = $configuration;
        return $this;
    }

    public function getType(): ?DataTableTypeInterface
    {
        return $this->type;
    }

    public function setType(DataTableTypeInterface $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getBatchActions(): array
    {
        return $this->batchActions;
    }

    public function setBatchActions(array $batchActions): static
    {
        $this->batchActions = $batchActions;
        return $this;
    }

    public function getGroupedBatchActions(): array
    {
        $result = [
            'groups' => [
                0 => []
            ],
            'dropdown' => [],
        ];

        foreach ($this->batchActions as $actionIdentifier => $batchAction) {
            if (isset($batchAction['dropdown']) && $batchAction['dropdown']) {
                $result['dropdown'][$actionIdentifier] = $batchAction;
                continue;
            }

            $groupIdentifier = isset($batchAction['group']) ? intval($batchAction['group']) : 0;
            if (!isset($result['groups'][$groupIdentifier])) {
                $result['groups'][$groupIdentifier] = [];
            }

            $result['groups'][$groupIdentifier][$actionIdentifier] = $batchAction;
        }

        // Reverse the groups
        ksort($result['groups']);

        return $result;
    }

    public function getCount()
    {
        if (null === $this->state) {
            throw new InvalidStateException('The DataTable does not know its state yet, did you call handleRequest?');
        }
        $builder = $this->getAdapter()->createQueryBuilder($this->state);
        $this->getAdapter()->buildCriteria($builder, $this->state);
        return $this->getAdapter()->getCount($builder, $builder->getRootAliases()[0]);
    }

    public function getIds($limit = null)
    {
        /* Old method

        $builder = $this->getAdapter()->createQueryBuilder($this->state);
        $this->getAdapter()->buildCriteria($builder, $this->state);
        return $this->getAdapter()->getIds($builder);

        */

        if (null === $this->state) {
            throw new InvalidStateException('The DataTable does not know its state yet, did you call handleRequest?');
        }
        $builder = $this->getAdapter()->createQueryBuilder($this->state);
        $this->getAdapter()->buildCriteria($builder, $this->state);
        if ($limit) {
            $builder->setMaxResults($limit);

            // Orderby is needed only with limit
            foreach ($this->state->getOrderBy() as list($column, $direction)) {
                /** @var AbstractColumn $column */
                if ($column->isOrderable()) {
                    $builder->addOrderBy($column->getOrderField(), $direction);
                }
            }
        }

        $ids = [];
        foreach ($builder->select($builder->getRootAliases()[0].'.id')->getQuery()->getResult() as $item) {
            $ids[$item['id']] = true;
        }

        return array_keys($ids);
    }

}
