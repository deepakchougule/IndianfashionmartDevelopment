<?php
/**
 * Refer to LICENSE.txt distributed with the Temando Shipping module for notice of license
 */
namespace Temando\Shipping\Sync;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Temando\Shipping\Model\Config\ModuleConfig;
use Temando\Shipping\Model\ResourceModel\EventStream\EventRepositoryInterface;
use Temando\Shipping\Model\StreamEventInterface;

/**
 * Temando Event Stream Processor
 *
 * @package  Temando\Shipping\Sync
 * @author   Benjamin Heuer <benjamin.heuer@netresearch.de>
 * @license  http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link     http://www.temando.com/
 */
class EventStreamProcessor
{
    /**
     * @var ModuleConfig
     */
    private $config;

    /**
     * @var EventListProcessor
     */
    private $eventListProcessor;

    /**
     * @var EventRepositoryInterface
     */
    private $streamEventRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param ModuleConfig $config
     * @param EventListProcessor $eventListProcessor
     * @param EventRepositoryInterface $streamEventRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        ModuleConfig $config,
        EventListProcessor $eventListProcessor,
        EventRepositoryInterface $streamEventRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->eventListProcessor = $eventListProcessor;
        $this->streamEventRepository = $streamEventRepository;
        $this->logger = $logger;
    }

    /**
     * @param int $iterations
     * @return void
     */
    public function processEvents($iterations = 10)
    {
        $streamId = $this->config->getStreamId();
        $eventList = EventList::fromArray([]);

        /* Filters treated as OR condition. Empty filter leads to empty result */
        $filter = [];
        if ($this->config->isSyncOrderEnabled()) {
            $filter[] = [
                "field" => "entity_type",
                "value" => "order"
            ];
        }
        if ($this->config->isSyncShipmentEnabled()) {
            $filter[] = [
                "field" => "entity_type",
                "value" => "shipment"
            ];
        }

        do {
            $iterations--;

            try {
                // obtain next events, pass on to list processor
                $streamEvents = $this->streamEventRepository->getEventList($streamId, $filter);
                $this->eventListProcessor->processEventList($streamEvents, $eventList);
            } catch (LocalizedException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
                continue;
            }

            $deleteStreamEvent = function (StreamEventInterface $streamEvent) use ($streamId, $eventList) {
                if (!$eventList->hasEvent($streamEvent->getEventId())) {
                    $this->streamEventRepository->delete($streamId, $streamEvent->getEventId());
                }
            };

            try {
                // delete events that were processed successfully (removed from event list)
                array_walk($streamEvents, $deleteStreamEvent);
            } catch (CouldNotDeleteException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }
        } while ($iterations > 0);
    }
}
