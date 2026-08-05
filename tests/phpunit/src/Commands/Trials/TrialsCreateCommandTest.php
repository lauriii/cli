<?php

declare(strict_types=1);

namespace Acquia\Cli\Tests\Commands\Trials;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Command\Trials\TrialsCreateCommand;
use Acquia\Cli\Exception\AcquiaCliException;
use Acquia\Cli\Tests\CommandTestBase;
use AcquiaCloudApi\Connector\ConnectorInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @property \Acquia\Cli\Command\Trials\TrialsCreateCommand $command
 */
class TrialsCreateCommandTest extends CommandTestBase
{
    private static string $trialsUrl = 'https://trials-service-prod.prod.mesh.cicd.acquia.io';

    private static string $trialId = '422ba415-dbc8-4830-bd10-1f80a8ce6dfc';

    protected Client|ObjectProphecy $httpClientProphecy;

    protected function createCommand(): CommandBase
    {
        $this->httpClientProphecy = $this->prophet->prophesize(Client::class);

        return new TrialsCreateCommand(
            $this->localMachineHelper,
            $this->datastoreCloud,
            $this->datastoreAcli,
            $this->cloudCredentials,
            $this->telemetryHelper,
            $this->acliRepoRoot,
            $this->clientServiceProphecy->reveal(),
            $this->sshHelper,
            $this->sshDir,
            $this->logger,
            $this->selfUpdateManager,
            $this->httpClientProphecy->reveal()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function trial(array $overrides = []): array
    {
        return array_merge([
            'failure_reason' => null,
            'percent_complete' => 100,
            'region' => 'us-east-1',
            'site_name' => 'My trial site',
            'site_template_id' => 'drupal_cms_starter',
            'site_url' => 'https://abc123.acquia-sites.com',
            'status' => 'COMPLETED',
            'subscription_id' => '9d5b0730-5898-45e9-8683-ef50dfc3d119',
            'trial_id' => self::$trialId,
        ], $overrides);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function response(int $code, ?array $body = null): Response
    {
        return new Response($code, [], $body === null ? '' : json_encode($body));
    }

    private function mockTrialsToken(): void
    {
        $this->httpClientProphecy->request('POST', ConnectorInterface::URL_ACCESS_TOKEN, Argument::any())
            ->willReturn(self::response(200, ['access_token' => 'trials-token']));
    }

    public function testCreateTrial(): void
    {
        $this->mockTrialsToken();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(self::response(404, ['error' => 'The resource you are trying to access does not exist, or you do not have access to it.']))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('POST', self::$trialsUrl . '/trials', Argument::that(static function (array $options): bool {
            return $options['json'] === [
                'region' => 'us-east-1',
                'site_name' => 'mysite',
                'site_template_id' => 'drupal_cms_starter',
            ] && $options['headers']['Authorization'] === 'Bearer trials-token';
        }))
            ->willReturn(self::response(200, self::trial([
                'percent_complete' => 14,
                'site_url' => null,
                'status' => 'SUBSCRIPTION_CLAIM_INITIATED',
                'subscription_id' => '',
            ])))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/' . self::$trialId, Argument::any())
            ->willReturn(
                self::response(200, self::trial([
                    'percent_complete' => 50,
                    'site_url' => null,
                    'status' => 'SITE_CREATION_INITIATED',
                ])),
                self::response(200, self::trial([
                    'percent_complete' => 50,
                    'site_url' => null,
                    'status' => 'SITE_CREATION_INITIATED',
                ])),
                self::response(200, self::trial(['site_name' => 'mysite']))
            )
            ->shouldBeCalled();

        $this->executeCommand(['--site-name' => 'mysite'], [], OutputInterface::VERBOSITY_NORMAL, false);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('Creating a free 14-day Acquia Cloud Platform trial site named mysite', $output);
        // Progress is printed only when the percentage changes.
        $this->assertSame(1, substr_count($output, '50% complete (SITE_CREATION_INITIATED)'));
        $this->assertStringContainsString('Your trial site is ready', $output);
        $this->assertStringContainsString('Site:         https://abc123.acquia-sites.com', $output);
        $this->assertStringContainsString('Site name:    mysite', $output);
        $this->assertStringContainsString('Subscription: 9d5b0730-5898-45e9-8683-ef50dfc3d119', $output);
    }

    /**
     * The option descriptions and help document the template catalog and
     * flow, in reading order.
     */
    public function testHelpDocumentsOptions(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertSame('A name for the trial site (defaults to "My trial site")', $definition->getOption('site-name')->getDescription());
        $this->assertSame('The site template to install: archimedes, byte, caresphere, convene, convivial_gov, drupal_cms_starter, haven, healthcare, local, provus_edu, pulse', $definition->getOption('template')->getDescription());
        $help = $this->command->getHelp();
        $lastPosition = -1;
        foreach (
            [
                'Creates a free 14-day Acquia Cloud Platform trial',
                'If your account already has a trial',
                'Provisioning takes a few minutes',
            ] as $paragraph
        ) {
            $position = strpos($help, $paragraph);
            $this->assertIsInt($position, "Help text mentions: $paragraph");
            $this->assertGreaterThan($lastPosition, $position, "Help text paragraph out of order: $paragraph");
            $lastPosition = $position;
        }
    }

    public function testExistingTrialReported(): void
    {
        $this->mockTrialsToken();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(self::response(200, self::trial()))
            ->shouldBeCalled();

        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('Your account already has a trial.', $output);
        $this->assertStringContainsString('https://abc123.acquia-sites.com', $output);
    }

    public function testResumeInProgressTrial(): void
    {
        $this->mockTrialsToken();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(self::response(200, self::trial([
                'percent_complete' => 14,
                'site_url' => null,
                'status' => 'SUBSCRIPTION_CLAIM_INITIATED',
            ])))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/' . self::$trialId, Argument::any())
            ->willReturn(self::response(200, self::trial()))
            ->shouldBeCalled();

        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('Your trial is already being provisioned — waiting for it to finish.', $output);
        $this->assertStringContainsString('Your trial site is ready', $output);
    }

    public function testRetryFailedTrial(): void
    {
        $this->mockTrialsToken();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(self::response(200, self::trial([
                'failure_reason' => 'step 1 failed: claim subscription: no subscription unit available',
                'site_url' => null,
                'status' => 'SUBSCRIPTION_CLAIM_FAILED',
                'subscription_id' => null,
            ])))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('POST', self::$trialsUrl . '/api/trials/' . self::$trialId, Argument::any())
            ->willReturn(self::response(200))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/' . self::$trialId, Argument::any())
            ->willReturn(self::response(200, self::trial()))
            ->shouldBeCalled();

        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('Your trial failed to provision (step 1 failed: claim subscription: no subscription unit available) — retrying it.', $output);
        $this->assertStringContainsString('Your trial site is ready', $output);
    }

    public function testTrialProvisioningFails(): void
    {
        $this->mockTrialsToken();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(self::response(404))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('POST', self::$trialsUrl . '/trials', Argument::any())
            ->willReturn(self::response(200, self::trial([
                'site_url' => null,
                'status' => 'SUBSCRIPTION_CLAIM_INITIATED',
            ])))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/' . self::$trialId, Argument::any())
            ->willReturn(self::response(200, self::trial([
                'failure_reason' => 'site creation failed',
                'site_url' => null,
                'status' => 'SITE_CREATION_FAILED',
            ])))
            ->shouldBeCalled();

        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Trial provisioning failed: site creation failed. Re-run `acli trials:create` to retry it.');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    public function testUnknownTemplate(): void
    {
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Unknown template nope. Available templates: archimedes, byte, caresphere, convene, convivial_gov, drupal_cms_starter, haven, healthcare, local, provus_edu, pulse');
        $this->executeCommand(['--template' => 'nope'], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    public function testTokenFailure(): void
    {
        $this->httpClientProphecy->request('POST', ConnectorInterface::URL_ACCESS_TOKEN, Argument::any())
            ->willReturn(self::response(400, ['error' => 'invalid_client']))
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Could not authenticate with the trials service. Check your Cloud Platform credentials (`acli auth:login`).');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * An expired token mid-flow is refreshed and the request retried once.
     */
    public function testExpiredTokenIsRefreshed(): void
    {
        $this->httpClientProphecy->request('POST', ConnectorInterface::URL_ACCESS_TOKEN, Argument::any())
            ->willReturn(
                self::response(200, ['access_token' => 'expired-token']),
                self::response(200, ['access_token' => 'fresh-token'])
            )
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(
                self::response(401, ['error' => 'The access token has expired.']),
                self::response(200, self::trial())
            )
            ->shouldBeCalled();

        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);

        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('Your account already has a trial.', $this->getDisplay());
    }

    public function testTrialsServiceError(): void
    {
        $this->mockTrialsToken();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(self::response(500, ['message' => 'Internal server error']))
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Trials service error (HTTP 500): Internal server error');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * If provisioning outlasts the timeout, the command must give up with
     * clear resume instructions.
     */
    #[Group('serial')]
    public function testTrialProvisioningTimeout(): void
    {
        putenv('ACLI_TRIAL_TIMEOUT=0');
        try {
            $this->mockTrialsToken();
            $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
                ->willReturn(self::response(404))
                ->shouldBeCalled();
            $this->httpClientProphecy->request('POST', self::$trialsUrl . '/trials', Argument::any())
                ->willReturn(self::response(200, self::trial([
                    'site_url' => null,
                    'status' => 'SUBSCRIPTION_CLAIM_INITIATED',
                ])))
                ->shouldBeCalled();
            $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/' . self::$trialId, Argument::any())
                ->willReturn(self::response(200, self::trial([
                    'site_url' => null,
                    'status' => 'SUBSCRIPTION_CLAIM_INITIATED',
                ])))
                ->shouldBeCalled();
            $this->expectException(AcquiaCliException::class);
            $this->expectExceptionMessage('The trial is still being provisioned. Re-run `acli trials:create` to keep waiting for it.');
            $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);
        } finally {
            putenv('ACLI_TRIAL_TIMEOUT');
        }
    }
}
