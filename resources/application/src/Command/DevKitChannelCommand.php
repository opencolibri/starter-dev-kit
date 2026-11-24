<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shipped by opencolibri/starter-dev-kit and refreshed on every run of its tasks: do not change it.
 */
#[AsCommand(
    name: 'sylius:dev-kit:channel',
    description: 'Print the name, locales and currencies of a channel as JSON, or change them',
)]
final class DevKitChannelCommand extends Command
{
    /** Locales and currencies created in this run, not flushed yet: the repositories cannot find them. */
    /** @var array{locale?: array<string, LocaleInterface>, currency?: array<string, CurrencyInterface>} */
    private array $created = [];

    /**
     * @param ChannelRepositoryInterface<ChannelInterface>  $channelRepository
     * @param RepositoryInterface<LocaleInterface>          $localeRepository
     * @param FactoryInterface<LocaleInterface>             $localeFactory
     * @param RepositoryInterface<CurrencyInterface>        $currencyRepository
     * @param FactoryInterface<CurrencyInterface>           $currencyFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'sylius.repository.channel')]
        private readonly ChannelRepositoryInterface $channelRepository,
        #[Autowire(service: 'sylius.repository.locale')]
        private readonly RepositoryInterface $localeRepository,
        #[Autowire(service: 'sylius.factory.locale')]
        private readonly FactoryInterface $localeFactory,
        #[Autowire(service: 'sylius.repository.currency')]
        private readonly RepositoryInterface $currencyRepository,
        #[Autowire(service: 'sylius.factory.currency')]
        private readonly FactoryInterface $currencyFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Channel code (e.g. WEB_STORE)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'New name of the channel')
            ->addOption('locales', null, InputOption::VALUE_REQUIRED, 'Enabled locales, comma-separated, replacing the current ones')
            ->addOption('default-locale', null, InputOption::VALUE_REQUIRED, 'Default locale')
            ->addOption('currencies', null, InputOption::VALUE_REQUIRED, 'Enabled currencies, comma-separated, the base one first')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = (string) $input->getArgument('code');
        $channel = $this->channelRepository->findOneByCode($code);

        if (!$channel instanceof ChannelInterface) {
            $output->writeln(\sprintf('<error>The %s channel was not found: load the fixtures first.</error>', $code));

            return Command::FAILURE;
        }

        // One transaction: the channel never keeps a default locale it does not enable.
        $this->entityManager->wrapInTransaction(function () use ($input, $channel): void {
            if (null !== $name = $input->getOption('name')) {
                $channel->setName((string) $name);
            }

            if (null !== $locales = self::codes($input->getOption('locales'))) {
                foreach ($channel->getLocales() as $locale) {
                    $channel->removeLocale($locale);
                }
                foreach ($locales as $locale) {
                    $channel->addLocale($this->locale($locale));
                }
            }

            if (null !== $defaultLocale = $input->getOption('default-locale')) {
                $channel->setDefaultLocale($this->locale((string) $defaultLocale));
            }

            if (null !== $currencies = self::codes($input->getOption('currencies'))) {
                foreach ($channel->getCurrencies() as $currency) {
                    $channel->removeCurrency($currency);
                }
                foreach ($currencies as $currency) {
                    $channel->addCurrency($this->currency($currency));
                }
                $channel->setBaseCurrency($this->currency($currencies[0]));
            }
        });

        $locales = array_map(static fn(LocaleInterface $locale): string => (string) $locale->getCode(), $channel->getLocales()->toArray());
        sort($locales);
        $base = (string) $channel->getBaseCurrency()?->getCode();
        $currencies = array_diff(array_map(static fn(CurrencyInterface $currency): string => (string) $currency->getCode(), $channel->getCurrencies()->toArray()), [$base]);
        sort($currencies);

        $output->writeln(json_encode([
            'name' => $channel->getName(),
            'locale' => $channel->getDefaultLocale()?->getCode(),
            'locales' => array_values($locales),
            'currencies' => array_values(array_filter([$base, ...$currencies])),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    private function locale(string $code): LocaleInterface
    {
        $locale = $this->created['locale'][$code] ?? $this->localeRepository->findOneBy(['code' => $code]);

        if (!$locale instanceof LocaleInterface) {
            $locale = $this->localeFactory->createNew();
            $locale->setCode($code);
            $this->entityManager->persist($locale);
            $this->created['locale'][$code] = $locale;
        }

        return $locale;
    }

    private function currency(string $code): CurrencyInterface
    {
        $currency = $this->created['currency'][$code] ?? $this->currencyRepository->findOneBy(['code' => $code]);

        if (!$currency instanceof CurrencyInterface) {
            $currency = $this->currencyFactory->createNew();
            $currency->setCode($code);
            $this->entityManager->persist($currency);
            $this->created['currency'][$code] = $currency;
        }

        return $currency;
    }

    /**
     * @return non-empty-list<string>|null
     */
    private static function codes(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }

        $codes = array_values(array_filter(array_map(trim(...), explode(',', (string) $value))));

        return [] === $codes ? null : $codes;
    }
}
