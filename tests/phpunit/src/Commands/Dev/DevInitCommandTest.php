<?php

declare(strict_types=1);

namespace Acquia\Cli\Tests\Commands\Dev;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Command\Dev\DevInitCommand;
use Acquia\Cli\Command\Trials\TrialsCreateCommand;
use Acquia\Cli\Exception\AcquiaCliException;
use Acquia\Cli\Tests\Commands\Ide\IdeHelper;
use Acquia\Cli\Tests\Commands\Pull\PullCommandTestBase;
use AcquiaCloudApi\Connector\ConnectorInterface;
use ArrayIterator;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @property \Acquia\Cli\Command\Dev\DevInitCommand $command
 */
class DevInitCommandTest extends PullCommandTestBase
{
    private static string $environmentId = '24-a47ac10b-58cc-4372-a567-0e02b2c3d470';

    public function setUp(): void
    {
        parent::setUp();
        IdeHelper::unsetCloudIdeEnvVars();
    }

    protected function createCommand(): CommandBase
    {
        $this->httpClientProphecy = $this->prophet->prophesize(Client::class);

        return new DevInitCommand(
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

    private function mockPrerequisitesFound(ObjectProphecy $localMachineHelper): void
    {
        foreach (['git', 'docker', 'ddev'] as $binary) {
            $localMachineHelper->commandExists($binary)
                ->willReturn(true)
                ->shouldBeCalled();
        }
        $process = $this->mockProcess();
        $localMachineHelper->execute(['docker', 'info'], null, null, false, 30)
            ->willReturn($process->reveal())
            ->shouldBeCalled();
    }

    /**
     * Mock a local SSH key whose contents match the given public key.
     */
    private function mockLocalSshKey(ObjectProphecy $localMachineHelper, string $publicKey): void
    {
        $finder = $this->prophet->prophesize(Finder::class);
        $finder->files()->willReturn($finder);
        $finder->in(Argument::type('string'))->willReturn($finder);
        $finder->name('*.pub')->willReturn($finder);
        $finder->ignoreUnreadableDirs()->willReturn($finder);
        $file = $this->prophet->prophesize(SplFileInfo::class);
        $file->getContents()->willReturn($publicKey);
        $file->getFilename()->willReturn('id_rsa.pub');
        $finder->getIterator()->willReturn(new ArrayIterator([$file->reveal()]));
        $localMachineHelper->getFinder()->willReturn($finder);
    }

    private function mockDdev(ObjectProphecy $localMachineHelper, string $dir, bool $siteInstalled): void
    {
        $process = $this->mockProcess();
        $localMachineHelper->execute(['ddev', 'config', '--auto'], Argument::type('callable'), $dir, false)
            ->willReturn($process->reveal());
        $localMachineHelper->execute(['ddev', 'start', '-y'], Argument::type('callable'), $dir, false, null)
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $drushStatus = $this->mockProcess($siteInstalled);
        $drushStatus->getOutput()->willReturn($siteInstalled ? 'Successful' : '');
        $localMachineHelper->execute(['ddev', 'drush', 'status', '--field=bootstrap'], null, $dir, false)
            ->willReturn($drushStatus->reveal())
            ->shouldBeCalled();
        $describe = $this->mockProcess();
        $describe->getOutput()->willReturn(json_encode(['raw' => ['primary_url' => 'https://site.ddev.site']]));
        $localMachineHelper->execute(['ddev', 'describe', '-j'], null, $dir, false)
            ->willReturn($describe->reveal())
            ->shouldBeCalled();
        $response = $this->prophet->prophesize(ResponseInterface::class);
        $response->getStatusCode()->willReturn(200);
        $this->httpClientProphecy->request('GET', 'https://site.ddev.site', Argument::any())
            ->willReturn($response->reveal())
            ->shouldBeCalled();
    }

    public function testDevInitMissingPrerequisites(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $localMachineHelper->commandExists('git')->willReturn(true);
        $localMachineHelper->commandExists('docker')->willReturn(false);
        $localMachineHelper->commandExists('ddev')->willReturn(false);
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Some required tools are missing');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL);
    }

    public function testDevInitDockerNotRunning(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        foreach (['git', 'docker', 'ddev'] as $binary) {
            $localMachineHelper->commandExists($binary)->willReturn(true);
        }
        $process = $this->mockProcess(false);
        $localMachineHelper->execute(['docker', 'info'], null, null, false, 30)
            ->willReturn($process->reveal());
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Docker is installed but not running');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL);
    }

    public function testDevInitNotAuthenticatedNonInteractive(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->clientServiceProphecy->isMachineAuthenticated()->willReturn(false);
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('This machine is not authenticated with the Cloud Platform');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    public function testDevInitNoSshKeyNonInteractive(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $this->mockRequest('getEnvironment', self::$environmentId);
        $this->mockRequest('getAccountSshKeys');
        $this->mockLocalSshKey($localMachineHelper, 'ssh-rsa KeyNotOnTheCloudPlatform');
        $localMachineHelper->commandExists('ssh-add')->willReturn(false);
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('No local SSH key is registered with the Cloud Platform');
        $this->executeCommand([
            'environmentId' => self::$environmentId,
        ], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * Without --dir, setup confirms the clone directory interactively with a
     * derived default, and clones into whatever the user answers.
     */
    public function testDevInitPromptsForCloneDirectory(): void
    {
        $answeredDir = Path::join($this->projectDir, 'my-custom-dir');
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $environment = $this->mockRequest('getEnvironment', self::$environmentId);
        $sshKeys = $this->mockRequest('getAccountSshKeys');
        $this->mockLocalSshKey($localMachineHelper, $sshKeys[0]->public_key);
        $localMachineHelper->readFile(Argument::type('string'))->willReturn('');
        $localMachineHelper->checkRequiredBinariesExist(['git'])
            ->shouldBeCalled();
        $process = $this->mockProcess(false);
        $localMachineHelper->execute([
            'git',
            'clone',
            $environment->vcs->url,
            $answeredDir,
        ], Argument::type('callable'), null, false, null, ['GIT_SSH_COMMAND' => 'ssh -o StrictHostKeyChecking=accept-new'])
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Failed to clone repository from the Cloud Platform');
        $this->executeCommand([
            'environmentId' => self::$environmentId,
        ], [
            // Where should the code be cloned?
            $answeredDir,
        ], OutputInterface::VERBOSITY_NORMAL);
    }

    /**
     * A failed clone must throw the clone error, not attempt the branch
     * checkout in a directory that does not exist.
     */
    public function testDevInitCloneFailure(): void
    {
        $dir = Path::join($this->projectDir, 'site');
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $environment = $this->mockRequest('getEnvironment', self::$environmentId);
        $sshKeys = $this->mockRequest('getAccountSshKeys');
        $this->mockLocalSshKey($localMachineHelper, $sshKeys[0]->public_key);
        $localMachineHelper->checkRequiredBinariesExist(['git'])
            ->shouldBeCalled();
        $process = $this->mockProcess(false);
        $localMachineHelper->execute([
            'git',
            'clone',
            $environment->vcs->url,
            $dir,
        ], Argument::type('callable'), null, false, null, ['GIT_SSH_COMMAND' => 'ssh -o StrictHostKeyChecking=accept-new'])
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute([
            'git',
            'checkout',
            $environment->vcs->path,
        ], Argument::cetera())
            ->shouldNotBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Failed to clone repository from the Cloud Platform');
        $this->executeCommand([
            '--dir' => $dir,
            'environmentId' => self::$environmentId,
        ], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * From nothing to a working site, non-interactively: clone, configure
     * ddev, start it, import the database, sync files.
     */
    public function testDevInitFreshNonInteractive(): void
    {
        $dir = Path::join($this->projectDir, 'site');
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $environment = $this->mockRequest('getEnvironment', self::$environmentId);
        $sshKeys = $this->mockRequest('getAccountSshKeys');
        $this->mockLocalSshKey($localMachineHelper, $sshKeys[0]->public_key);
        $this->mockGetFilesystem($localMachineHelper);

        // Clone.
        $localMachineHelper->checkRequiredBinariesExist(['git'])
            ->shouldBeCalled();
        $process = $this->mockProcess();
        $localMachineHelper->execute([
            'git',
            'clone',
            $environment->vcs->url,
            $dir,
        ], Argument::type('callable'), null, false, null, ['GIT_SSH_COMMAND' => 'ssh -o StrictHostKeyChecking=accept-new'])
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute([
            'git',
            'checkout',
            $environment->vcs->path,
        ], Argument::type('callable'), $dir, false)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        $this->mockDdev($localMachineHelper, $dir, false);

        // Database download and import.
        $sshHelper = $this->mockSshHelper();
        $this->mockListSites($sshHelper);
        $this->command->sshHelper = $sshHelper->reveal();
        $this->mockGetBackup($environment);
        $dumpPath = Path::join(sys_get_temp_dir(), 'dev-my_db-my_dbdev-2012-05-15T12:00:00Z.sql.gz');
        $localMachineHelper->checkRequiredBinariesExist(['gunzip'])
            ->shouldBeCalled();
        $localMachineHelper->executeFromCmd('bash -o pipefail -c "gunzip -c \"$DUMP_FILEPATH\" | ddev import-db"', Argument::type('callable'), $dir, false, null, ['DUMP_FILEPATH' => $dumpPath])
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        // Files.
        $localMachineHelper->checkRequiredBinariesExist(['rsync'])
            ->shouldBeCalled();
        $localMachineHelper->execute([
            'rsync',
            '-avPhze',
            'ssh -o StrictHostKeyChecking=accept-new',
            $environment->ssh_url . ':/mnt/files/site.dev/sites/default/files/',
            $dir . '/docroot/sites/default/files',
        ], Argument::type('callable'), null, false)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        // Drush cache rebuild and sanitization.
        $localMachineHelper->execute(['ddev', 'drush', 'cache:rebuild', '--yes'], Argument::type('callable'), $dir, false, null)
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute(['ddev', 'drush', 'sql:sanitize', '--yes'], Argument::type('callable'), $dir, false, null)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        $this->executeCommand([
            '--dir' => $dir,
            'environmentId' => self::$environmentId,
        ], [], OutputInterface::VERBOSITY_NORMAL, false);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('✓ Found git, docker, and ddev', $output);
        $this->assertStringContainsString('✓ Authenticated as', $output);
        $this->assertStringContainsString('✓ SSH key id_rsa.pub is registered with the Cloud Platform', $output);
        $this->assertStringContainsString('Your local development environment is ready: https://site.ddev.site', $output);
        $this->assertStringContainsString('ddev drush uli', $output);
        $this->assertStringContainsString('git push', $output);
        $this->assertStringContainsString('runs the master branch', $output);
        // The project was linked to the Cloud application.
        $this->assertFileExists(Path::join($dir, '.acquia-cli.yml'));
        $this->assertStringContainsString($environment->application->uuid, file_get_contents(Path::join($dir, '.acquia-cli.yml')));
    }

    private static string $trialsUrl = 'https://trials-service-prod.prod.mesh.cicd.acquia.io';

    /**
     * Register the real trials:create command in the test application so the
     * setup command can run it as a sub-command, sharing the same mocked
     * HTTP client and Cloud API client.
     */
    private function registerTrialsCreateCommand(): void
    {
        $this->application->add(new TrialsCreateCommand(
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
        ));
    }

    /**
     * Mock the trials service: no existing trial, creation succeeds, and the
     * trial completes on the first status poll.
     */
    private function mockTrialsService(): void
    {
        $this->httpClientProphecy->request('POST', ConnectorInterface::URL_ACCESS_TOKEN, Argument::any())
            ->willReturn(new Response(200, [], json_encode(['access_token' => 'trials-token'])));
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(new Response(404, [], ''));
        $trial = json_encode([
            'failure_reason' => null,
            'percent_complete' => 100,
            'site_name' => 'My trial site',
            'site_url' => 'https://abc123.acquia-sites.com',
            'status' => 'COMPLETED',
            'subscription_id' => '9d5b0730-5898-45e9-8683-ef50dfc3d119',
            'trial_id' => 'test-trial-id',
        ]);
        $this->httpClientProphecy->request('POST', self::$trialsUrl . '/trials', Argument::any())
            ->willReturn(new Response(200, [], $trial))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/test-trial-id', Argument::any())
            ->willReturn(new Response(200, [], $trial));
    }

    /**
     * With zero applications, non-interactive setup must not create a trial
     * implicitly: it fails with precise instructions instead.
     */
    public function testDevInitZeroApplicationsNonInteractive(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $this->clientProphecy->request('get', '/applications')
            ->willReturn([])
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Your account has no Cloud applications yet. Create one with a free trial first: re-run with `acli dev:init --new`, run `acli trials:create`, or run `acli dev:init` interactively.');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * Declining the trial offer ends setup with a clear message instead of a
     * dead end.
     */
    public function testDevInitZeroApplicationsTrialDeclined(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $this->clientProphecy->request('get', '/applications')
            ->willReturn([])
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('There is nothing to set up without an application. Re-run `acli dev:init` when you are ready to create one.');
        $this->executeCommand([], [
            // Create a free trial now?
            'n',
        ], OutputInterface::VERBOSITY_NORMAL);
    }

    /**
     * Without --new, an account that has applications must go down the
     * normal select-an-existing-application path, not the trial path.
     */
    public function testDevInitExistingApplicationsSkipTrialFlow(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $applicationsResponse = self::getMockResponseFromSpec('/applications', 'get', '200');
        $this->clientProphecy->request('get', '/applications')
            ->willReturn($applicationsResponse->_embedded->items)
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Could not determine Cloud Application');
        $this->executeCommand([], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * The help text documents the trial flow, in reading order.
     */
    public function testDevInitHelpDocumentsTrialFlow(): void
    {
        $help = $this->command->getHelp();
        $lastPosition = -1;
        foreach (
            [
                'takes you from nothing',
                'Prerequisites: git, Docker, and ddev',
                'If your account has no applications yet',
                'Every step is skipped automatically',
                'For non-interactive use',
            ] as $paragraph
        ) {
            $position = strpos($help, $paragraph);
            $this->assertIsInt($position, "Help text mentions: $paragraph");
            $this->assertGreaterThan($lastPosition, $position, "Help text paragraph out of order: $paragraph");
            $lastPosition = $position;
        }
    }

    /**
     * With zero applications, setup creates a trial via trials:create, polls
     * until the new application appears, waits for a provisioned
     * environment, and continues to a working local site.
     */
    public function testDevInitCreatesTrialWhenNoApplications(): void
    {
        $dir = Path::join($this->projectDir, 'site');
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $this->registerTrialsCreateCommand();
        $this->mockTrialsService();

        // First call: the zero-applications check. Second call: the first
        // poll after the trial completes finds the new application.
        $applicationsResponse = self::getMockResponseFromSpec('/applications', 'get', '200');
        $application = $applicationsResponse->_embedded->items[0];
        $this->clientProphecy->request('get', '/applications')
            ->willReturn([], [$application])
            ->shouldBeCalled();
        $localMachineHelper->isBrowserAvailable()->willReturn(false);
        $localMachineHelper->startBrowser(Argument::any())->shouldNotBeCalled();
        // The provisioning wait first sees only a production environment (not
        // cloneable), then Dev appears; the environment prompt then gets the
        // full list including the node environment, which it must filter out.
        $environmentsResponse = self::getMockResponseFromSpec('/applications/{applicationUuid}/environments', 'get', '200');
        $environments = $environmentsResponse->_embedded->items;
        $this->clientProphecy->request('get', "/applications/$application->uuid/environments")
            ->willReturn([$environments[1]], [$environments[0], $environments[1]], $environments)
            ->shouldBeCalled();
        $environment = $environments[0];

        $sshKeys = $this->mockRequest('getAccountSshKeys');
        $this->mockLocalSshKey($localMachineHelper, $sshKeys[0]->public_key);
        $this->mockGetFilesystem($localMachineHelper);

        // Clone.
        $localMachineHelper->checkRequiredBinariesExist(['git'])
            ->shouldBeCalled();
        $process = $this->mockProcess();
        $localMachineHelper->execute([
            'git',
            'clone',
            $environment->vcs->url,
            $dir,
        ], Argument::type('callable'), null, false, null, ['GIT_SSH_COMMAND' => 'ssh -o StrictHostKeyChecking=accept-new'])
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute([
            'git',
            'checkout',
            $environment->vcs->path,
        ], Argument::type('callable'), $dir, false)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        $this->mockDdev($localMachineHelper, $dir, false);

        // Database download and import.
        $sshHelper = $this->mockSshHelper();
        $this->mockListSites($sshHelper);
        $this->command->sshHelper = $sshHelper->reveal();
        $this->mockGetBackup($environment);
        $dumpPath = Path::join(sys_get_temp_dir(), 'dev-my_db-my_dbdev-2012-05-15T12:00:00Z.sql.gz');
        $localMachineHelper->checkRequiredBinariesExist(['gunzip'])
            ->shouldBeCalled();
        $localMachineHelper->executeFromCmd('bash -o pipefail -c "gunzip -c \"$DUMP_FILEPATH\" | ddev import-db"', Argument::type('callable'), $dir, false, null, ['DUMP_FILEPATH' => $dumpPath])
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        // Files.
        $localMachineHelper->checkRequiredBinariesExist(['rsync'])
            ->shouldBeCalled();
        $localMachineHelper->execute([
            'rsync',
            '-avPhze',
            'ssh -o StrictHostKeyChecking=accept-new',
            $environment->ssh_url . ':/mnt/files/site.dev/sites/default/files/',
            $dir . '/docroot/sites/default/files',
        ], Argument::type('callable'), null, false)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        // Drush cache rebuild and sanitization.
        $localMachineHelper->execute(['ddev', 'drush', 'cache:rebuild', '--yes'], Argument::type('callable'), $dir, false, null)
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute(['ddev', 'drush', 'sql:sanitize', '--yes'], Argument::type('callable'), $dir, false, null)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        $this->executeCommand([
            '--dir' => $dir,
        ], [
            // Create a free trial now?
            'y',
            // Choose a Cloud Platform environment (default: Dev).
            '',
            // Choose a database (default: my_db).
            '',
        ], OutputInterface::VERBOSITY_NORMAL);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString("You don't have any Cloud applications yet", $output);
        $this->assertStringContainsString('Your trial site is ready', $output);
        // The sub-command must run non-interactively (site name defaulted).
        $this->assertStringNotContainsString('What should the trial site be called?', $output);
        $this->assertStringContainsString('Found your new application Sample application 1', $output);
        $this->assertStringContainsString('environments are still being provisioned', $output);
        $this->assertStringContainsString('Press Ctrl+C to stop waiting', $output);
        $this->assertStringContainsString('Using Cloud Application Sample application 1', $output);
        // Production and node environments are not offered for local setup.
        $this->assertStringNotContainsString('Production, prod', $output);
        $this->assertStringNotContainsString('Stage, test', $output);
        $this->assertStringContainsString('Your local development environment is ready: https://site.ddev.site', $output);
        $this->assertFileExists(Path::join($dir, '.acquia-cli.yml'));
        $this->assertStringContainsString($environment->application->uuid, file_get_contents(Path::join($dir, '.acquia-cli.yml')));
    }

    /**
     * --new works non-interactively (the trial is created with defaults),
     * and a failed trial surfaces the trials service's reason.
     */
    public function testDevInitNewOptionTrialFailure(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $this->registerTrialsCreateCommand();
        $applicationsResponse = self::getMockResponseFromSpec('/applications', 'get', '200');
        $this->clientProphecy->request('get', '/applications')
            ->willReturn([$applicationsResponse->_embedded->items[0]])
            ->shouldBeCalled();
        $this->httpClientProphecy->request('POST', ConnectorInterface::URL_ACCESS_TOKEN, Argument::any())
            ->willReturn(new Response(200, [], json_encode(['access_token' => 'trials-token'])));
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(new Response(404, [], ''));
        $trial = static fn (string $status, ?string $reason) => json_encode([
            'failure_reason' => $reason,
            'percent_complete' => 14,
            'site_name' => 'My trial site',
            'site_url' => null,
            'status' => $status,
            'subscription_id' => null,
            'trial_id' => 'test-trial-id',
        ]);
        $this->httpClientProphecy->request('POST', self::$trialsUrl . '/trials', Argument::any())
            ->willReturn(new Response(200, [], $trial('SUBSCRIPTION_CLAIM_INITIATED', null)))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/test-trial-id', Argument::any())
            ->willReturn(new Response(200, [], $trial('SUBSCRIPTION_CLAIM_FAILED', 'no subscription unit available')))
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Trial provisioning failed: no subscription unit available. Re-run `acli trials:create` to retry it.');
        $this->executeCommand(['--new' => true], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * --new with zero applications skips the explanation/confirmation and
     * goes straight to trial creation, interactively or not.
     */
    public function testDevInitNewOptionZeroApplications(): void
    {
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $this->registerTrialsCreateCommand();
        $this->clientProphecy->request('get', '/applications')
            ->willReturn([])
            ->shouldBeCalled();
        $this->httpClientProphecy->request('POST', ConnectorInterface::URL_ACCESS_TOKEN, Argument::any())
            ->willReturn(new Response(200, [], json_encode(['access_token' => 'trials-token'])));
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials', Argument::any())
            ->willReturn(new Response(404, [], ''));
        $trial = static fn (string $status, ?string $reason) => json_encode([
            'failure_reason' => $reason,
            'percent_complete' => 14,
            'site_name' => 'My trial site',
            'site_url' => null,
            'status' => $status,
            'subscription_id' => null,
            'trial_id' => 'test-trial-id',
        ]);
        $this->httpClientProphecy->request('POST', self::$trialsUrl . '/trials', Argument::any())
            ->willReturn(new Response(200, [], $trial('SUBSCRIPTION_CLAIM_INITIATED', null)))
            ->shouldBeCalled();
        $this->httpClientProphecy->request('GET', self::$trialsUrl . '/api/trials/test-trial-id', Argument::any())
            ->willReturn(new Response(200, [], $trial('SUBSCRIPTION_CLAIM_FAILED', 'no subscription unit available')))
            ->shouldBeCalled();
        $this->expectException(AcquiaCliException::class);
        $this->expectExceptionMessage('Trial provisioning failed: no subscription unit available. Re-run `acli trials:create` to retry it.');
        $this->executeCommand(['--new' => true], [], OutputInterface::VERBOSITY_NORMAL, false);
    }

    /**
     * A user with existing applications can still create a new one: the
     * application picker offers a create-a-new-application choice that runs
     * the same trial signup flow, without needing --new.
     */
    public function testDevInitNewApplicationFromPicker(): void
    {
        $dir = Path::join($this->projectDir, 'site');
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');

        $this->registerTrialsCreateCommand();
        $this->mockTrialsService();
        $applicationsResponse = self::getMockResponseFromSpec('/applications', 'get', '200');
        [$existingApplication, $newApplication] = $applicationsResponse->_embedded->items;
        // First call: the zero-applications check. Second: the picker.
        // Third: the poll after the trial completes finds the new application.
        $this->clientProphecy->request('get', '/applications')
            ->willReturn([$existingApplication], [$existingApplication], [$existingApplication, $newApplication])
            ->shouldBeCalled();
        $this->clientProphecy->request('get', "/applications/$newApplication->uuid")
            ->willReturn($newApplication)
            ->shouldBeCalled();
        $localMachineHelper->isBrowserAvailable()->willReturn(true);
        // Provisioning wait: first only production exists, then Dev appears.
        $environmentsResponse = self::getMockResponseFromSpec('/applications/{applicationUuid}/environments', 'get', '200');
        $environments = $environmentsResponse->_embedded->items;
        $this->clientProphecy->request('get', "/applications/$newApplication->uuid/environments")
            ->willReturn([$environments[1]], [$environments[0], $environments[1]])
            ->shouldBeCalled();
        $environment = $environments[0];

        $sshKeys = $this->mockRequest('getAccountSshKeys');
        $this->mockLocalSshKey($localMachineHelper, $sshKeys[0]->public_key);
        $this->mockGetFilesystem($localMachineHelper);

        $localMachineHelper->checkRequiredBinariesExist(['git'])
            ->shouldBeCalled();
        $process = $this->mockProcess();
        $localMachineHelper->execute([
            'git',
            'clone',
            $environment->vcs->url,
            $dir,
        ], Argument::type('callable'), null, false, null, ['GIT_SSH_COMMAND' => 'ssh -o StrictHostKeyChecking=accept-new'])
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute([
            'git',
            'checkout',
            $environment->vcs->path,
        ], Argument::type('callable'), $dir, false)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        $this->mockDdev($localMachineHelper, $dir, false);
        $localMachineHelper->startBrowser('https://site.ddev.site')
            ->willReturn(true)
            ->shouldBeCalled();

        $sshHelper = $this->mockSshHelper();
        $this->mockListSites($sshHelper);
        $this->command->sshHelper = $sshHelper->reveal();
        $this->mockGetBackup($environment);
        $dumpPath = Path::join(sys_get_temp_dir(), 'dev-my_db-my_dbdev-2012-05-15T12:00:00Z.sql.gz');
        $localMachineHelper->checkRequiredBinariesExist(['gunzip'])
            ->shouldBeCalled();
        $localMachineHelper->executeFromCmd('bash -o pipefail -c "gunzip -c \"$DUMP_FILEPATH\" | ddev import-db"', Argument::type('callable'), $dir, false, null, ['DUMP_FILEPATH' => $dumpPath])
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->checkRequiredBinariesExist(['rsync'])
            ->shouldBeCalled();
        $localMachineHelper->execute([
            'rsync',
            '-avPhze',
            'ssh -o StrictHostKeyChecking=accept-new',
            $environment->ssh_url . ':/mnt/files/site.dev/sites/default/files/',
            $dir . '/docroot/sites/default/files',
        ], Argument::type('callable'), null, false)
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute(['ddev', 'drush', 'cache:rebuild', '--yes'], Argument::type('callable'), $dir, false, null)
            ->willReturn($process->reveal())
            ->shouldBeCalled();
        $localMachineHelper->execute(['ddev', 'drush', 'sql:sanitize', '--yes'], Argument::type('callable'), $dir, false, null)
            ->willReturn($process->reveal())
            ->shouldBeCalled();

        $this->executeCommand([
            '--dir' => $dir,
        ], [
            // Search for a Cloud application matching the local git config?
            'n',
            // Select a Cloud Platform application (the create-new choice).
            'Create a new application (free 14-day Acquia trial)',
            // Link the Cloud application to this repository?
            'n',
            // Choose a Cloud Platform environment (default: Dev).
            '',
            // Choose a database (default: my_db).
            '',
        ], OutputInterface::VERBOSITY_NORMAL);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('Create a new application (free 14-day Acquia trial)', $output);
        $this->assertStringContainsString('Found your new application Sample application 2', $output);
        $this->assertStringContainsString('Using Cloud Application Sample application 2', $output);
        $this->assertStringContainsString('Your local development environment is ready: https://site.ddev.site', $output);
    }

    /**
     * If the trial completes but its application never appears in the Cloud
     * API, the poll must give up with clear resume instructions rather than
     * hanging forever.
     */
    #[Group('serial')]
    public function testDevInitTrialApplicationTimeout(): void
    {
        putenv('ACLI_TRIAL_TIMEOUT=0');
        try {
            $localMachineHelper = $this->mockLocalMachineHelper();
            $this->mockPrerequisitesFound($localMachineHelper);
            $this->mockRequest('getAccount');
            $this->registerTrialsCreateCommand();
            $this->mockTrialsService();
            $this->clientProphecy->request('get', '/applications')
                ->willReturn([])
                ->shouldBeCalled();
            $this->expectException(AcquiaCliException::class);
            $this->expectExceptionMessage('The trial exists but its application has not appeared yet. Re-run `acli dev:init` in a minute to continue where you left off.');
            $this->executeCommand([], [
                // Create a free trial now?
                'y',
            ], OutputInterface::VERBOSITY_NORMAL);
        } finally {
            putenv('ACLI_TRIAL_TIMEOUT');
        }
    }

    /**
     * Re-running setup on an existing checkout with an installed site skips
     * every completed step instead of redoing it.
     */
    public function testDevInitResumeSkipsCompletedSteps(): void
    {
        $dir = $this->projectDir;
        $localMachineHelper = $this->mockLocalMachineHelper();
        $this->mockPrerequisitesFound($localMachineHelper);
        $this->mockRequest('getAccount');
        $environment = $this->mockRequest('getEnvironment', self::$environmentId);
        $sshKeys = $this->mockRequest('getAccountSshKeys');
        $this->mockLocalSshKey($localMachineHelper, $sshKeys[0]->public_key);
        $this->mockGetFilesystem($localMachineHelper);
        $localMachineHelper->isBrowserAvailable()->willReturn(false);

        // An existing checkout of this application with ddev already
        // configured and composer dependencies already installed.
        $this->fs->dumpFile(Path::join($dir, '.git', 'config'), 'url = ' . $environment->vcs->url);
        $localMachineHelper->readFile(Path::join($dir, '.git', 'config'))
            ->willReturn('url = ' . $environment->vcs->url);
        $this->fs->dumpFile(Path::join($dir, '.ddev', 'config.yaml'), 'name: site');
        $this->fs->dumpFile(Path::join($dir, 'composer.json'), '{}');
        $this->fs->mkdir(Path::join($dir, 'vendor'));

        $this->mockDdev($localMachineHelper, $dir, true);

        $this->executeCommand([
            '--dir' => $dir,
            'environmentId' => self::$environmentId,
        ], [], OutputInterface::VERBOSITY_NORMAL);

        $output = $this->getDisplay();
        $this->assertSame(0, $this->getStatusCode());
        $this->assertStringContainsString('✓ Code already cloned to ' . $dir, $output);
        $this->assertStringContainsString('✓ ddev is already configured', $output);
        $this->assertStringContainsString('✓ Composer dependencies already installed', $output);
        $this->assertStringContainsString('✓ Site database already present', $output);
        $this->assertStringContainsString('Your local development environment is ready: https://site.ddev.site', $output);
    }
}
