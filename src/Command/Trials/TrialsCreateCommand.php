<?php

declare(strict_types=1);

namespace Acquia\Cli\Command\Trials;

use Acquia\Cli\ApiCredentialsInterface;
use Acquia\Cli\Attribute\RequireAuth;
use Acquia\Cli\CloudApi\ClientService;
use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\DataStore\AcquiaCliDatastore;
use Acquia\Cli\DataStore\CloudDataStore;
use Acquia\Cli\Exception\AcquiaCliException;
use Acquia\Cli\Helpers\LocalMachineHelper;
use Acquia\Cli\Helpers\SshHelper;
use Acquia\Cli\Helpers\TelemetryHelper;
use AcquiaCloudApi\Connector\ConnectorInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SelfUpdate\SelfUpdateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[RequireAuth]
#[AsCommand(name: 'trials:create', description: 'Create a free Acquia Cloud Platform trial site', aliases: ['trial:create'])]
final class TrialsCreateCommand extends CommandBase
{
    /**
     * @todo Fetch the template catalog from the trials service once it
     * exposes one; hard-coded for now.
     */
    private const TEMPLATES = [
        'archimedes',
        'byte',
        'caresphere',
        'convene',
        'convivial_gov',
        'drupal_cms_starter',
        'haven',
        'healthcare',
        'local',
        'provus_edu',
        'pulse',
    ];

    private const DEFAULT_TEMPLATE = 'drupal_cms_starter';

    private const DEFAULT_REGION = 'us-east-1';

    private const DEFAULT_SITE_NAME = 'My trial site';

    /**
     * Overridable via the ACLI_TRIALS_SERVICE_URL environment variable, e.g.
     * to target the staging service.
     */
    private const TRIALS_SERVICE_URL = 'https://trials-service-prod.prod.mesh.cicd.acquia.io';

    private ?string $trialsToken = null;

    public function __construct(
        public LocalMachineHelper $localMachineHelper,
        protected CloudDataStore $datastoreCloud,
        protected AcquiaCliDatastore $datastoreAcli,
        protected ApiCredentialsInterface $cloudCredentials,
        protected TelemetryHelper $telemetryHelper,
        protected string $projectDir,
        protected ClientService $cloudApiClientService,
        public SshHelper $sshHelper,
        protected string $sshDir,
        LoggerInterface $logger,
        public SelfUpdateManager $selfUpdateManager,
        protected Client $httpClient
    ) {
        parent::__construct($this->localMachineHelper, $this->datastoreCloud, $this->datastoreAcli, $this->cloudCredentials, $this->telemetryHelper, $this->projectDir, $this->cloudApiClientService, $this->sshHelper, $this->sshDir, $logger, $this->selfUpdateManager);
    }

    protected function configure(): void
    {
        $this
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'A name for the trial site (defaults to "' . self::DEFAULT_SITE_NAME . '")')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'The site template to install: ' . implode(', ', self::TEMPLATES), self::DEFAULT_TEMPLATE)
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'The cloud region to provision in', self::DEFAULT_REGION)
            ->setHelp('Creates a free 14-day Acquia Cloud Platform trial: a new subscription with an application, Dev/Stage/Prod environments, and a ready-to-use Drupal site built from the chosen template.'
                . "\n\nIf your account already has a trial, this command reports it, resumes waiting for it to finish provisioning, or retries it if it failed — so it is always safe to re-run."
                . "\n\nProvisioning takes a few minutes; the command waits (up to 30 minutes, or ACLI_TRIAL_TIMEOUT seconds) and reports the site URL when ready.");
    }

    /**
     * @throws \Acquia\Cli\Exception\AcquiaCliException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $template = $input->getOption('template');
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new AcquiaCliException('Unknown template {template}. Available templates: {templates}', [
                'template' => $template,
                'templates' => implode(', ', self::TEMPLATES),
            ]);
        }
        $trial = $this->trialsRequest('GET', '/api/trials');
        if ($trial === null) {
            $siteName = $input->getOption('site-name');
            if ($siteName === null) {
                // In non-interactive mode ask() returns the default.
                $siteName = $this->io->ask('What should the trial site be called?', self::DEFAULT_SITE_NAME);
            }
            $this->io->writeln("Creating a free 14-day Acquia Cloud Platform trial site named <options=bold>$siteName</> ($template, {$input->getOption('region')})...");
            $trial = $this->trialsRequest('POST', '/trials', [
                'region' => $input->getOption('region'),
                'site_name' => $siteName,
                'site_template_id' => $template,
            ]);
        } elseif ($trial->status === 'COMPLETED') {
            $this->io->writeln('Your account already has a trial.');
            $this->printTrial($trial);
            return Command::SUCCESS;
        } elseif ($this->trialFailed($trial)) {
            $this->io->writeln('Your trial failed to provision (' . ($trial->failure_reason ?? $trial->status) . ') — retrying it.');
            $this->trialsRequest('POST', '/api/trials/' . $trial->trial_id);
        } else {
            $this->io->writeln('Your trial is already being provisioned — waiting for it to finish.');
        }
        $lastPercent = null;
        $trial = $this->pollCloud(
            function () use ($trial, &$lastPercent): ?object {
                $current = $this->trialsRequest('GET', '/api/trials/' . $trial->trial_id);
                if ($current === null) {
                    return null;
                }
                $percent = $current->percent_complete ?? null;
                if ($percent !== null && $percent !== $lastPercent) {
                    $this->io->writeln("  $percent% complete ($current->status)");
                    $lastPercent = $percent;
                }
                if (!$this->trialFailed($current) && $current->status !== 'COMPLETED') {
                    return null;
                }
                return $current;
            },
            'Provisioning your trial site — this usually takes a few minutes.',
            'The trial is still being provisioned. Re-run `acli trials:create` to keep waiting for it.'
        );
        if ($this->trialFailed($trial)) {
            throw new AcquiaCliException('Trial provisioning failed: {reason}. Re-run `acli trials:create` to retry it.', [
                'reason' => $trial->failure_reason ?? $trial->status,
            ]);
        }
        $this->io->success('Your trial site is ready.');
        $this->printTrial($trial);

        return Command::SUCCESS;
    }

    private function trialFailed(object $trial): bool
    {
        return str_contains($trial->status, 'FAILED');
    }

    private function printTrial(object $trial): void
    {
        $this->io->writeln([
            'Site:         ' . ($trial->site_url ?? '(not yet available)'),
            'Site name:    ' . $trial->site_name,
            'Subscription: ' . ($trial->subscription_id ?? '(pending)'),
        ]);
    }

    /**
     * Call the trials service. Mind the inconsistent path prefixes: trials
     * are created with POST /trials and retried with POST /api/trials/{id},
     * while reads all live under /api/trials.
     *
     * @param array<string, string>|null $body
     * @return object|null The decoded response, or null for a GET 404 (no
     *     such trial).
     * @throws \Acquia\Cli\Exception\AcquiaCliException
     */
    private function trialsRequest(string $method, string $path, ?array $body = null): ?object
    {
        $url = (getenv('ACLI_TRIALS_SERVICE_URL') ?: self::TRIALS_SERVICE_URL) . $path;
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $this->trialsToken()],
            'http_errors' => false,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $this->httpClient->request($method, $url, $options);
        if ($response->getStatusCode() === 401) {
            // The token expired mid-wait: fetch a fresh one and retry once.
            $this->trialsToken = null;
            $options['headers']['Authorization'] = 'Bearer ' . $this->trialsToken();
            $response = $this->httpClient->request($method, $url, $options);
        }
        if ($response->getStatusCode() === 404 && $method === 'GET') {
            return null;
        }
        $body = (string) $response->getBody();
        $data = json_decode($body);
        if ($response->getStatusCode() >= 400) {
            throw new AcquiaCliException('Trials service error (HTTP {code}): {message}', [
                'code' => $response->getStatusCode(),
                'message' => $data->message ?? $data->error ?? $body,
            ]);
        }
        return is_object($data) ? $data : null;
    }

    /**
     * The trials service accepts the same OAuth client_credentials tokens as
     * the Cloud Platform API.
     *
     * @throws \Acquia\Cli\Exception\AcquiaCliException
     */
    private function trialsToken(): string
    {
        if ($this->trialsToken === null) {
            $response = $this->httpClient->request('POST', ConnectorInterface::URL_ACCESS_TOKEN, [
                'form_params' => [
                    'client_id' => $this->cloudCredentials->getCloudKey(),
                    'client_secret' => $this->cloudCredentials->getCloudSecret(),
                    'grant_type' => 'client_credentials',
                ],
                'http_errors' => false,
            ]);
            $data = json_decode((string) $response->getBody());
            if (!isset($data->access_token)) {
                throw new AcquiaCliException('Could not authenticate with the trials service. Check your Cloud Platform credentials (`acli auth:login`).');
            }
            $this->trialsToken = $data->access_token;
        }
        return $this->trialsToken;
    }
}
