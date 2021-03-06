<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\CurrencyExchangeBundle\Service;

use Doctrine\Common\Cache\CacheProvider;
use ONGR\CurrencyExchangeBundle\Document\CurrencyDocument;
use ONGR\CurrencyExchangeBundle\Document\RatesObject;
use ONGR\CurrencyExchangeBundle\Driver\CurrencyDriverInterface;
use ONGR\CurrencyExchangeBundle\Exception\RatesNotLoadedException;
use ONGR\ElasticsearchBundle\Collection\Collection;
use ONGR\ElasticsearchBundle\Service\Manager;
use Psr\Log\LoggerAwareTrait;

/**
 * This class provides currency rates.
 */
class CurrencyRatesService
{
    use LoggerAwareTrait;

    /**
     * @var null|array
     */
    public $rates = null;

    /**
     * @var CurrencyDriverInterface
     */
    private $driver;

    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var Manager
     */
    private $manager;

    /**
     * @param CurrencyDriverInterface $driver  Currency exchange driver.
     * @param Manager                 $manager ES Manager.
     * @param CacheProvider           $cache    Cache pool.
     */
    public function __construct(
        CurrencyDriverInterface $driver,
        Manager $manager,
        CacheProvider $cache
    ) {
        $this->driver = $driver;
        $this->manager = $manager;
        $this->cache = $cache;
    }

    /**
     * This method returns exchange rates.
     *
     * @param string|null $date
     *
     * @throws RatesNotLoadedException
     * @return array
     */
    public function getRates($date = null)
    {
        $date = $date ? $date : $this->getCurrentDate();

        if (isset($this->rates[$date])) {
            return $this->rates[$date];
        }

        $rates = $this->cache->fetch($date);
        if ($rates) {
            $this->rates[$date] = $rates;
            return $rates;
        }

        $rates = $this->getCurrencyFromEs($date);
        if ($rates) {
            $this->rates[$date] = $rates;
            return $rates;
        }

        $rates = $this->reloadRates($date);
        if ($rates) {
            $this->rates[$date] = $rates;
            return $rates;
        }

        throw new RatesNotLoadedException(
            'Currency rates for '.$date.' are not loaded and could not be loaded on demand'
        );
    }

    /**
     * Returns currency rates from ES.
     *
     * @param string|null $date
     *
     * @return array
     */
    private function getCurrencyFromEs($date = null)
    {
        $date = $date ? $date : $this->getCurrentDate();

        $rates = [];
        #TODO Should be used service instead of getRepository
        $repository = $this->manager->getRepository('ONGRCurrencyExchangeBundle:CurrencyDocument');
        /** @var CurrencyDocument $currency */
        $currency = $repository->findOneBy(['date' => $date]);

        if ($currency) {
            /** @var RatesObject $rate */
            foreach ($currency->getRates() as $rate) {
                $rates[$rate->getName()] = $rate->getValue();
            }
//            $this->logger && $this->logger->info('Rates returned from ES.');
            return $rates;
        }

        return null;
    }

    /**
     * Reloads rates using given driver.
     *
     * @param string|null $date
     *
     * @return array|null
     */
    public function reloadRates($date = null)
    {
        $date = $date ? $date : $this->getCurrentDate();

        $rawRates = $this->driver->getRates($date);

        if ($rawRates) {
            $this->rates[$date] = $rawRates;
            $this->cache->save($date, $rawRates);

            $repository = $this->manager->getRepository('ONGRCurrencyExchangeBundle:CurrencyDocument');
            /** @var CurrencyDocument $currency */
            $document = $repository->findOneBy(['date' => $date]);

            if (!$document) {
                $document = new CurrencyDocument();
            }

            $rates = [];
            foreach ($rawRates as $rate => $value) {
                $rateObj = new RatesObject();
                $rateObj->setName($rate);
                $rateObj->setValue($value);
                $rates[] = $rateObj;
            }

            $document->setRates(new Collection($rates));
            $this->manager->persist($document);
            $this->manager->commit();

            return $rawRates;
        }

        return null;
    }

    /**
     * Returns actual base currency name.
     *
     * @return string
     */
    public function getBaseCurrency()
    {
        return $this->driver->getBaseCurrency();
    }

    /**
     * Returns formatted current date
     *
     * @returns string
     */
    private function getCurrentDate()
    {
        return date('Y-m-d');
    }
}
