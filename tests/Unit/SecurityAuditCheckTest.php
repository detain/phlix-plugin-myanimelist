<?php

/**
 * Security Audit Check Test.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Myanimelist\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * S246 — behaviour of `scripts/security-audit-check.php`, by execution.
 *
 * Lane token: S440MYANIMELISTGUARDT5X9.
 *
 * ## The defect these tests pin shut
 *
 * This repository had **no security audit gate at all**: nothing in CI ever
 * asked whether a locked dependency carried a published advisory.
 *
 * On 2026-08-06 CVE-2026-67434 (HIGH, OS command injection) landed against
 * `squizlabs/php_codesniffer`. Only phlix-server went red, because only
 * phlix-server audited its development dependencies. A green that cannot go red
 * is not evidence.
 *
 * ## What is asserted, and why each half exists
 *
 *  1. **The dev half is really audited.** {@see testAnAdvisoryAgainstADevelopmentDependencyBlocks()}
 *     is the direct regression test: a `require-dev` advisory must exit 1 and be
 *     labelled `[require-dev]` so the reader can still see the scope.
 *  2. **`--no-dev` cannot appear.** The audit flags are a declared constant,
 *     read here rather than pattern-matched out of prose, and the workflow is
 *     parsed with its comments stripped — the new job carries a comment naming
 *     the offending flag, and a detector that matches its own documentation is
 *     not a detector.
 *  3. **The gate cannot be neutered.** The audit job must carry no
 *     `continue-on-error` and no `if:` condition — the job slice runs to
 *     end-of-file, so step-level flags are caught too — and the workflow must
 *     still run on `pull_request`.
 *  4. **The corpus is stated and floored.** A gate that ran and inspected zero
 *     packages is the commonest false pass in this estate and looks exactly like
 *     a clean run, so the printed size is checked against an independent count,
 *     and both floors are driven to failure.
 *  5. **Cannot-measure fails.** Missing lock, unparseable lock, missing payload,
 *     empty payload, unparseable payload, unrecognised payload shape and an
 *     unreachable advisory repository each exit 1 rather than passing.
 *  6. **The blocking/advisory split holds.** Abandonment and config-ignored
 *     advisories are loud but non-blocking; a real advisory blocks even when
 *     they are present, so the advisory half cannot become decorative.
 *  7. **The command cannot be neutered at the shell.** The audit step's single
 *     `run:` line is pinned by EQUALITY to the bare gate invocation — `|| true`
 *     keeps every containment check green while making the step always succeed
 *     (C2-review hole H1), so containment is not enough.
 *  8. **The flags cannot be bypassed at the spawn.** The process call that runs
 *     `composer audit` must take its argv from the AUDIT_ARGUMENTS constant,
 *     and the literal `'audit'` must occur nowhere else in the script — an
 *     inline argv would leave the constant, and every assertion reading it,
 *     intact while auditing a different command (C2-review hole H2).
 *
 * ## 2026-10-07 hardening port (owner-ratified, S440 census closure)
 *
 * The reference guard (phlix-plugin-sample-theme 856900e) was hardened against
 * two C2-review holes this suite did not yet cover: the audit run line was
 * checked only by CONTAINMENT — appending `|| true` kept every check green
 * (hole H1) — and nothing pinned the composer spawn site to the AUDIT_ARGUMENTS
 * constant, so an inline argv could audit a different command while every
 * flag assertion reading the constant stayed green (hole H2). Items 7 and 8,
 * {@see testTheAuditRunLineIsTheBareGateInvocation()} and
 * {@see testTheComposerAuditSpawnBuildsItsArgvFromTheConstant()}, are ported
 * byte-identical from the hardened template, together with the lane-token and
 * committed-lock markers for parity. This repository's gate script is
 * token-identical to the reference family and its audit-job workflow slice is
 * md5-identical, so no assertion needed bending to fit — verified by execution
 * before commit.
 *
 * @internal
 */
final class SecurityAuditCheckTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../scripts/security-audit-check.php';

    private const WORKFLOW = __DIR__ . '/../../.github/workflows/test.yml';

    private const REAL_LOCK = __DIR__ . '/../../composer.lock';

    /** S440 parity-port lane marker — a real code string, self-asserted by {@see testThisIsTheS440GateSuite()}, so it survives tokenisation and stays greppable. */
    private const LANE_TOKEN = 'S440MYANIMELISTGUARDT5X9';

    /**
     * Floors the script enforces, restated here deliberately.
     *
     * Measured on this repository's committed lock 2026-08-06: 38 packages
     * (10 require, 28 require-dev). {@see testFloorsMatchTheScript()} keeps
     * the two copies honest, so lowering the floor in the script alone reddens
     * this suite instead of quietly shrinking the gate.
     */
    private const MIN_PACKAGES = 32;

    private const MIN_DEV_PACKAGES = 24;

    private string $workDir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/s246-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'temp dir for the audit fixtures');
        $this->workDir = $dir;
    }

    protected function tearDown(): void
    {
        if ($this->workDir === '') {
            return;
        }

        foreach ((array) glob($this->workDir . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->workDir)) {
            rmdir($this->workDir);
        }

        $this->workDir = '';

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // The gate exists and is wired into CI.
    // -----------------------------------------------------------------------

    public function testTheGateScriptExists(): void
    {
        self::assertFileExists(self::SCRIPT);
    }

    /**
     * S440 lane marker: the guard suite belongs to the S440 parity port, and the
     * token is real code (a string constant) so it survives tokenisation rather
     * than living only in a comment that php_strip_whitespace would erase.
     */
    public function testThisIsTheS440GateSuite(): void
    {
        self::assertSame('S440MYANIMELISTGUARDT5X9', self::LANE_TOKEN);
    }

    public function testTheCommittedLockExists(): void
    {
        // S440: parity requires a COMMITTED lock of the codeload-tarball deps.
        // Without it the gate measures nothing and the audit job cannot run.
        self::assertFileExists(self::REAL_LOCK);
    }

    public function testTheWorkflowRunsTheGateScript(): void
    {
        self::assertStringContainsString(
            'php scripts/security-audit-check.php',
            $this->workflowWithoutComments(),
            'test.yml must invoke the audit gate.',
        );
    }

    /**
     * A gate that only runs on demand is not a gate. The workflow must fire on
     * pull requests, which is the event the audit is meant to block.
     */
    public function testTheWorkflowRunsOnPullRequests(): void
    {
        self::assertMatchesRegularExpression(
            '/^\s*pull_request:?\s*$/m',
            $this->workflowWithoutComments(),
            'The audit must run on pull requests, not only on demand.',
        );
    }

    /**
     * The whole defect in one line. The workflow is read with comments removed
     * because the new job carries a comment explaining what `--no-dev` did, and
     * a check that matches its own documentation proves nothing.
     */
    public function testTheWorkflowDoesNotExcludeDevelopmentDependencies(): void
    {
        $yaml = $this->workflowWithoutComments();

        self::assertStringNotContainsString(
            '--no-dev',
            $yaml,
            'S246: excluding require-dev from the audit is what made a HIGH advisory against '
            . 'squizlabs/php_codesniffer invisible to CI. Do not introduce it.',
        );

        // Non-vacuity, in both directions: the stripper must actually strip
        // (the new job is commented) and must not have emptied the file.
        $raw = (string) file_get_contents(self::WORKFLOW);
        self::assertLessThan(strlen($raw), strlen($yaml), 'the comment stripper removed nothing at all');
        self::assertStringContainsString('composer-audit:', $yaml);
        self::assertStringContainsString('php scripts/security-audit-check.php', $yaml);
    }

    public function testTheAuditJobIsNotNeutered(): void
    {
        $job = $this->auditJob();

        self::assertStringNotContainsString(
            'continue-on-error',
            $job,
            'A security gate that cannot fail the build is the defect this replaces.',
        );

        self::assertDoesNotMatchRegularExpression(
            '/^\s*if:/m',
            $job,
            'A conditional audit job can be skipped, and a skipped check reads as a success.',
        );
    }

    /**
     * The flags are read from the declared constant rather than grepped out of
     * the script body, so this cannot accidentally match a comment.
     */
    public function testTheAuditIsInvokedWithoutTheDevExclusion(): void
    {
        $flags = $this->auditArguments();

        self::assertContains('audit', $flags);
        self::assertContains('--locked', $flags);
        self::assertContains('--format=json', $flags);
        self::assertNotContains(
            '--no-dev',
            $flags,
            'S246: the audit must cover require-dev packages.',
        );
    }

    /**
     * C2-review hole H1: appending `|| true` to the audit run line keeps every
     * containment assertion above green — a neutered command still CONTAINS the
     * gate invocation. The law here is therefore equality: the audit job's one
     * and only `run:` value must be exactly the bare gate invocation. No shell
     * operators (`||`, `&&`, `;`), no redirections, no env-prefix games, and no
     * block scalar hiding extra lines. Step-level `continue-on-error` and `if:`
     * are already caught by {@see testTheAuditJobIsNotNeutered()} — its slice
     * runs from `composer-audit:` to end-of-file — so they are not duplicated.
     */
    public function testTheAuditRunLineIsTheBareGateInvocation(): void
    {
        $matches = [];
        $count   = preg_match_all('/^\s*run:[ \t]*(.*?)[ \t]*$/m', $this->auditJob(), $matches);

        self::assertSame(
            1,
            $count,
            'The audit job must contain exactly one run step so this law binds a single, '
            . 'identifiable command — a second run line is a new escape vector.',
        );

        // Safe: the count assertion above guarantees exactly one capture.
        self::assertSame(
            'php scripts/security-audit-check.php',
            $matches[1][0],
            'The audit run line must be exactly the bare gate invocation. Trailing shell '
            . 'operators (|| true, && :, ; true), output redirections (2>/dev/null) or an '
            . 'env prefix keep the command *contained* in the step — which is all the '
            . 'containment tests can see — while making it unconditionally green (H1).',
        );
    }

    /**
     * C2-review hole H2: every flag assertion above reads the AUDIT_ARGUMENTS
     * CONSTANT, but nothing pinned the composer spawn site to USE it. Rewrite
     * captureAuditPayload() to build an inline argv — dropping `--locked` while
     * the constant survives review untouched — and the gate audits a different
     * command than the one under test, with the flag law still green. Three
     * interlocking rules close that:
     *
     *  1. The spawn function must still exist — a rename is a silent escape hatch.
     *  2. Inside its body (comments token-stripped, so prose cannot satisfy the
     *     regex) exactly one process spawn must take its argv from the constant.
     *  3. The string literal 'audit' may appear exactly once in the whole script.
     *     {@see testTheAuditIsInvokedWithoutTheDevExclusion()} proves that
     *     occurrence sits inside the constant declaration, so no inline audit
     *     argv can exist anywhere — at any call site, in either quoting style.
     */
    public function testTheComposerAuditSpawnBuildsItsArgvFromTheConstant(): void
    {
        $source = (string) file_get_contents(self::SCRIPT);
        $body   = $this->functionBody($source, 'captureAuditPayload');

        self::assertSame(
            1,
            substr_count($body, 'runProcess('),
            'captureAuditPayload() must contain exactly one process spawn — the audit '
            . 'command must be one identifiable call, not a sequence of spawns.',
        );

        self::assertMatchesRegularExpression(
            '/runProcess\s*\([^;]*AUDIT_ARGUMENTS/',
            $body,
            'The composer-audit spawn must build its argv from the AUDIT_ARGUMENTS constant '
            . '(H2). An inline list leaves the constant — and every flag assertion above — '
            . 'intact while running something else.',
        );

        self::assertSame(
            1,
            $this->countStringLiteralsEqualTo($source, 'audit'),
            "The bare string literal 'audit' may appear exactly once in the script — inside "
            . 'the AUDIT_ARGUMENTS declaration. A second occurrence is an inline composer argv '
            . 'somewhere that bypasses the constant (H2).',
        );
    }

    public function testFloorsMatchTheScript(): void
    {
        $source = (string) file_get_contents(self::SCRIPT);

        self::assertMatchesRegularExpression(
            '/const MIN_AUDITED_PACKAGES = ' . self::MIN_PACKAGES . ';/',
            $source,
            'Lowering the corpus floor is how this gate would be neutered.',
        );

        self::assertMatchesRegularExpression(
            '/const MIN_AUDITED_DEV_PACKAGES = ' . self::MIN_DEV_PACKAGES . ';/',
            $source,
            'Lowering the require-dev floor re-opens exactly the hole S246 closed.',
        );
    }

    // -----------------------------------------------------------------------
    // The corpus is stated out loud.
    // -----------------------------------------------------------------------

    public function testItPrintsTheCorpusItExamined(): void
    {
        $runtime = self::MIN_PACKAGES;
        $dev     = self::MIN_DEV_PACKAGES;

        $result = $this->runGate($this->payload(['advisories' => []]), $this->lock($runtime, $dev));

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            sprintf(
                'Audit corpus: %d locked package(s) — %d require, %d require-dev',
                $runtime + $dev,
                $runtime,
                $dev,
            ),
            $result['output'],
        );
        self::assertStringContainsString(
            sprintf('No security advisories affecting the %d locked package(s)', $runtime + $dev),
            $result['output'],
        );
    }

    /**
     * Against the repo's own lock, with no lock argument, the reported corpus
     * must equal an independent count of that lock — and that count must clear
     * both floors, so the floors are known to be satisfiable here.
     *
     * @throws JsonException
     */
    public function testTheDefaultCorpusIsTheReposOwnLock(): void
    {
        /** @var array{packages: list<array<string, mixed>>, packages-dev: list<array<string, mixed>>} $lock */
        $lock    = json_decode((string) file_get_contents(self::REAL_LOCK), true, 512, JSON_THROW_ON_ERROR);
        $runtime = count($lock['packages']);
        $dev     = count($lock['packages-dev']);

        self::assertGreaterThanOrEqual(
            self::MIN_PACKAGES,
            $runtime + $dev,
            'the repo lock must clear the total floor the gate enforces',
        );
        self::assertGreaterThanOrEqual(
            self::MIN_DEV_PACKAGES,
            $dev,
            'the repo lock must clear the require-dev floor the gate enforces',
        );

        $result = $this->runGate($this->payload(['advisories' => []]));

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            sprintf(
                'Audit corpus: %d locked package(s) — %d require, %d require-dev',
                $runtime + $dev,
                $runtime,
                $dev,
            ),
            $result['output'],
        );
    }

    public function testACorpusBelowTheTotalFloorFails(): void
    {
        $result = $this->runGate($this->payload(['advisories' => []]), $this->lock(0, self::MIN_DEV_PACKAGES));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            sprintf(
                '::error::Audit corpus is %d package(s), below the floor of %d',
                self::MIN_DEV_PACKAGES,
                self::MIN_PACKAGES,
            ),
            $result['output'],
        );
    }

    /**
     * The direct anti-regression: a lock whose dev half has been emptied — which
     * is exactly what `--no-dev` produces — must not read as a clean audit.
     */
    public function testACorpusWithNoDevelopmentPackagesFails(): void
    {
        $result = $this->runGate(
            $this->payload(['advisories' => []]),
            $this->lock(self::MIN_PACKAGES + self::MIN_DEV_PACKAGES, 0),
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            sprintf('::error::Only 0 require-dev package(s) in the corpus, below the floor of %d', self::MIN_DEV_PACKAGES),
            $result['output'],
        );
        self::assertStringContainsString('Do NOT restore --no-dev', $result['output']);
    }

    public function testAMissingLockFails(): void
    {
        $result = $this->runGate($this->payload(['advisories' => []]), $this->workDir . '/absent.lock');

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('::error::composer.lock not found', $result['output']);
    }

    public function testAnUnparseableLockFails(): void
    {
        $path = $this->workDir . '/broken.lock';
        file_put_contents($path, '{ "packages": ');

        $result = $this->runGate($this->payload(['advisories' => []]), $path);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is not parseable JSON', $result['output']);
    }

    public function testALockWithoutAPackagesKeyFails(): void
    {
        $path = $this->workDir . '/notalock.lock';
        file_put_contents($path, '{"hello":"world"}');

        $result = $this->runGate($this->payload(['advisories' => []]), $path);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('has no "packages" key', $result['output']);
    }

    // -----------------------------------------------------------------------
    // The blocking verdict — including the dev half, which is the point.
    // -----------------------------------------------------------------------

    /**
     * The exact 2026-08-06 finding, replayed: a HIGH advisory against a package
     * that lives in `require-dev`. With no audit gate, or with a `--no-dev` one,
     * this was invisible.
     */
    public function testAnAdvisoryAgainstADevelopmentDependencyBlocks(): void
    {
        $lock = $this->lock(
            self::MIN_PACKAGES,
            self::MIN_DEV_PACKAGES,
            ['squizlabs/php_codesniffer' => ['scope' => 'dev', 'version' => '4.0.1']],
        );

        $result = $this->runGate(
            $this->payload([
                'advisories' => [
                    'squizlabs/php_codesniffer' => [[
                        'advisoryId'       => 'PKSA-vvvv-wwww-xxxx',
                        'packageName'      => 'squizlabs/php_codesniffer',
                        'affectedVersions' => '<3.13.6|>=4.0.0,<4.0.2',
                        'title'            => 'OS command injection in the diff/report writer',
                        'cve'              => 'CVE-2026-67434',
                        'link'             => 'https://github.com/advisories/GHSA-hmqg-cxww-wqhq',
                        'severity'         => 'high',
                    ]],
                ],
            ]),
            $lock,
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::Security audit FAILED — 1 advisory/ies affecting 1 package(s) (0 require, 1 require-dev).',
            $result['output'],
        );
        self::assertStringContainsString(
            'squizlabs/php_codesniffer [require-dev] locked at 4.0.1 (affected: <3.13.6|>=4.0.0,<4.0.2)',
            $result['output'],
        );
        self::assertStringContainsString('[HIGH] CVE-2026-67434 — OS command injection', $result['output']);
        self::assertStringContainsString('https://github.com/advisories/GHSA-hmqg-cxww-wqhq', $result['output']);
    }

    public function testAnAdvisoryAgainstARuntimeDependencyBlocksAndIsLabelledAsSuch(): void
    {
        $lock = $this->lock(
            self::MIN_PACKAGES,
            self::MIN_DEV_PACKAGES,
            ['vendor/runtime-thing' => ['scope' => 'runtime', 'version' => '1.0.0']],
        );

        $result = $this->runGate(
            $this->payload([
                'advisories' => [
                    'vendor/runtime-thing' => [[
                        'advisoryId'       => 'PKSA-aaaa-bbbb-cccc',
                        'affectedVersions' => '<1.0.1',
                        'title'            => 'Remote code execution',
                        'cve'              => 'CVE-2026-00001',
                        'severity'         => 'critical',
                    ]],
                ],
            ]),
            $lock,
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('(1 require, 0 require-dev)', $result['output']);
        self::assertStringContainsString('vendor/runtime-thing [require] locked at 1.0.0', $result['output']);
        self::assertStringContainsString('[CRITICAL] CVE-2026-00001', $result['output']);
    }

    /**
     * A succeeding control beside the failure: the same corpus, the same script,
     * an empty advisory set. Without this the red above could be produced by
     * anything at all.
     */
    public function testTheSameCorpusPassesWhenThereAreNoAdvisories(): void
    {
        $lock = $this->lock(
            self::MIN_PACKAGES,
            self::MIN_DEV_PACKAGES,
            ['squizlabs/php_codesniffer' => ['scope' => 'dev', 'version' => '4.0.4']],
        );

        $result = $this->runGate($this->payload(['advisories' => [], 'abandoned' => []]), $lock);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('Security audit passed.', $result['output']);
        self::assertStringNotContainsString('::error::', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Advisory-only findings are loud but do not block.
    // -----------------------------------------------------------------------

    public function testAbandonedPackagesWarnLoudlyButDoNotBlock(): void
    {
        $lock = $this->lock(
            self::MIN_PACKAGES,
            self::MIN_DEV_PACKAGES,
            ['fgrosse/phpasn1' => ['scope' => 'runtime', 'version' => '2.5.0']],
        );

        $result = $this->runGate(
            $this->payload([
                'advisories' => [],
                'abandoned'  => ['fgrosse/phpasn1' => '', 'web-auth/metadata-service' => 'web-auth/webauthn-lib'],
            ]),
            $lock,
        );

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('::warning::2 abandoned package(s). ADVISORY ONLY', $result['output']);
        self::assertStringContainsString('fgrosse/phpasn1 [require] — no replacement suggested', $result['output']);
        self::assertStringContainsString(
            'web-auth/metadata-service [not in lock] — replaced by web-auth/webauthn-lib',
            $result['output'],
        );
        self::assertStringContainsString('Security audit passed.', $result['output']);
    }

    public function testAnAdvisoryStillBlocksWhenAbandonedPackagesArePresent(): void
    {
        $lock = $this->lock(
            self::MIN_PACKAGES,
            self::MIN_DEV_PACKAGES,
            ['vendor/broken' => ['scope' => 'dev', 'version' => '2.0.0']],
        );

        $result = $this->runGate(
            $this->payload([
                'advisories' => [
                    'vendor/broken' => [[
                        'advisoryId' => 'PKSA-dddd-eeee-ffff',
                        'title'      => 'Path traversal',
                        'cve'        => 'CVE-2026-00002',
                        'severity'   => 'medium',
                    ]],
                ],
                'abandoned' => ['fgrosse/phpasn1' => ''],
            ]),
            $lock,
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('::warning::1 abandoned package(s)', $result['output']);
        self::assertStringContainsString('::error::Security audit FAILED', $result['output']);
    }

    public function testConfigIgnoredAdvisoriesAreReportedAsAcknowledgedNotHidden(): void
    {
        $lock = $this->lock(
            self::MIN_PACKAGES,
            self::MIN_DEV_PACKAGES,
            ['vendor/unfixable' => ['scope' => 'runtime', 'version' => '3.1.0']],
        );

        $result = $this->runGate(
            $this->payload([
                'advisories'         => [],
                'ignored-advisories' => [
                    'vendor/unfixable' => [[
                        'advisoryId' => 'PKSA-gggg-hhhh-iiii',
                        'title'      => 'Denial of service',
                        'cve'        => 'CVE-2026-00003',
                        'severity'   => 'low',
                    ]],
                ],
            ]),
            $lock,
        );

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::notice::1 security advisory/ies affecting 1 package(s) are IGNORED',
            $result['output'],
        );
        self::assertStringContainsString('vendor/unfixable [require] locked at 3.1.0', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Cannot-measure must fail, never skip.
    // -----------------------------------------------------------------------

    public function testAnUnreachableAdvisoryRepositoryFails(): void
    {
        $result = $this->runGate(
            $this->payload(['advisories' => [], 'unreachable-repositories' => ['https://repo.packagist.org']]),
            $this->lock(self::MIN_PACKAGES, self::MIN_DEV_PACKAGES),
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::1 advisory repository/ies were unreachable — the audit measured nothing.',
            $result['output'],
        );
    }

    public function testAMissingPayloadFails(): void
    {
        $result = $this->runGate(
            $this->workDir . '/absent.json',
            $this->lock(self::MIN_PACKAGES, self::MIN_DEV_PACKAGES),
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('does not exist', $result['output']);
    }

    public function testAnEmptyPayloadFails(): void
    {
        $path = $this->workDir . '/empty.json';
        file_put_contents($path, "   \n");

        $result = $this->runGate($path, $this->lock(self::MIN_PACKAGES, self::MIN_DEV_PACKAGES));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is empty', $result['output']);
    }

    public function testAnUnparseablePayloadFails(): void
    {
        $path = $this->workDir . '/bad.json';
        file_put_contents($path, 'not json at all');

        $result = $this->runGate($path, $this->lock(self::MIN_PACKAGES, self::MIN_DEV_PACKAGES));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is not parseable JSON', $result['output']);
    }

    public function testAPayloadWithoutAnAdvisoriesKeyFails(): void
    {
        $result = $this->runGate(
            $this->payload(['something-else' => []]),
            $this->lock(self::MIN_PACKAGES, self::MIN_DEV_PACKAGES),
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('has no "advisories" key', $result['output']);
    }

    public function testAMissingComposerBinaryFailsInsteadOfSkipping(): void
    {
        $result = $this->runGate(
            null,
            $this->lock(self::MIN_PACKAGES, self::MIN_DEV_PACKAGES),
            ['COMPOSER_BIN' => '/nonexistent/composer'],
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::Cannot run "/nonexistent/composer --version"',
            $result['output'],
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Run the gate as a subprocess and capture its merged output and exit code.
     *
     * @param array<string, string> $env
     *
     * @return array{exit: int, output: string}
     */
    private function runGate(?string $payloadPath, ?string $lockPath = null, array $env = []): array
    {
        $command = ['php', self::SCRIPT];

        if ($payloadPath !== null) {
            $command[] = $payloadPath;

            if ($lockPath !== null) {
                $command[] = $lockPath;
            }
        } elseif ($lockPath !== null) {
            $command[] = '';
            $command[] = $lockPath;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];
        $process     = proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            $env === [] ? null : $env + ['PATH' => (string) getenv('PATH')],
        );

        self::assertNotFalse($process, 'could not start the gate script');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $stdout . $stderr];
    }

    /**
     * Write a `composer audit --format=json` payload and return its path.
     *
     * @param array<string, mixed> $payload
     */
    private function payload(array $payload): string
    {
        $path = $this->workDir . '/audit-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Write a synthetic `composer.lock` with a known package count.
     *
     * Named packages are placed in the requested section so the scope label can
     * be asserted; the filler packages make up the remainder of the count.
     *
     * @param array<string, array{scope: 'runtime'|'dev', version: string}> $named
     */
    private function lock(int $runtime, int $dev, array $named = []): string
    {
        /** @var array{packages: list<array{name: string, version: string}>, packages-dev: list<array{name: string, version: string}>} $sections */
        $sections = ['packages' => [], 'packages-dev' => []];

        foreach ($named as $name => $spec) {
            $key              = $spec['scope'] === 'dev' ? 'packages-dev' : 'packages';
            $sections[$key][] = ['name' => $name, 'version' => $spec['version']];
        }

        while (count($sections['packages']) < $runtime) {
            $sections['packages'][] = ['name' => 'filler/runtime-' . count($sections['packages']), 'version' => '1.0.0'];
        }

        while (count($sections['packages-dev']) < $dev) {
            $sections['packages-dev'][] = ['name' => 'filler/dev-' . count($sections['packages-dev']), 'version' => '1.0.0'];
        }

        $path = $this->workDir . '/composer-' . bin2hex(random_bytes(4)) . '.lock';
        file_put_contents($path, (string) json_encode($sections, JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * The workflow with every YAML comment removed.
     *
     * The audit job documents what `--no-dev` used to do, so a raw substring
     * search over this file would match the explanation rather than the
     * configuration.
     */
    private function workflowWithoutComments(): string
    {
        $lines = explode("\n", (string) file_get_contents(self::WORKFLOW));
        $kept  = [];

        foreach ($lines as $line) {
            $stripped = preg_replace('/(?:^|\s)#.*$/', '', $line);
            $kept[]   = is_string($stripped) ? $stripped : $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Everything in the workflow from the audit job onwards, comments stripped.
     *
     * The audit job is deliberately the LAST job in the file, so this slice is
     * exactly that job — a neutering flag on some other job cannot satisfy or
     * break the assertions above.
     */
    private function auditJob(): string
    {
        $yaml   = $this->workflowWithoutComments();
        $offset = strpos($yaml, 'composer-audit:');

        self::assertNotFalse($offset, 'the workflow must declare a composer-audit job');

        $job = substr($yaml, $offset);

        self::assertStringContainsString(
            'php scripts/security-audit-check.php',
            $job,
            'the audit job must be the last job in the workflow, so this slice is only that job',
        );

        return $job;
    }

    /**
     * The audit flags the script declares, read from the constant itself.
     *
     * @return list<string>
     */
    private function auditArguments(): array
    {
        $source  = (string) file_get_contents(self::SCRIPT);
        $matches = [];

        self::assertSame(
            1,
            preg_match('/const AUDIT_ARGUMENTS = \[(.*?)\];/s', $source, $matches),
            'scripts/security-audit-check.php must declare AUDIT_ARGUMENTS — this test reads the '
            . 'declared flags rather than pattern-matching prose, and an absent constant is a '
            . 'silent pass otherwise.',
        );

        $declaration = $matches[1] ?? '';

        self::assertNotSame('', $declaration, 'AUDIT_ARGUMENTS matched but captured nothing.');

        $flags = [];
        $found = [];

        if (preg_match_all("/'([^']+)'/", $declaration, $found) > 0) {
            $flags = $found[1];
        }

        self::assertNotSame([], $flags, 'AUDIT_ARGUMENTS parsed to an empty list — the assertion below would be vacuous.');

        return $flags;
    }

    /**
     * Count the constant string tokens whose value is exactly $wanted, in either
     * quoting style. Comments, docblocks and multi-word prose are not constant
     * strings, so documentation about the audit cannot inflate the count — and a
     * detector that matches its own documentation is not a detector.
     */
    private function countStringLiteralsEqualTo(string $source, string $wanted): int
    {
        $count = 0;

        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            if (trim($token[1], "'\"") === $wanted) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The body of a declared function, comments removed, located by walking
     * `token_get_all()` so braces inside comments or strings cannot shift the
     * slice. Fails loudly when the function is absent — a renamed or deleted
     * spawn function must redden the suite, never make the spawn-site law
     * silently vacuous.
     */
    private function functionBody(string $source, string $functionName): string
    {
        $tokens = token_get_all($source);
        $total  = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = null;

            for ($j = $i + 1; $j < $total; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $nameIndex = $j;
                    break;
                }
            }

            if (
                $nameIndex === null
                || !is_array($tokens[$nameIndex])
                || $tokens[$nameIndex][1] !== $functionName
            ) {
                continue;
            }

            $body    = '';
            $depth   = 0;
            $started = false;

            for ($k = $nameIndex + 1; $k < $total; $k++) {
                $token = $tokens[$k];

                if (is_array($token)) {
                    if ($started && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                        $body .= $token[1];
                    }

                    continue;
                }

                if ($token === '{') {
                    $depth++;
                    $started = true;

                    if ($depth > 1) {
                        $body .= $token;
                    }

                    continue;
                }

                if ($token === '}') {
                    $depth--;

                    if ($depth === 0) {
                        return trim($body);
                    }

                    $body .= $token;

                    continue;
                }

                if ($started) {
                    $body .= $token;
                }
            }

            self::fail(
                sprintf('Could not find the closing brace of %s() — unbalanced braces.', $functionName)
            );
        }

        self::fail(sprintf(
            'scripts/security-audit-check.php must declare %s() — that is the composer-audit '
            . 'spawn site this law pins. Renaming or deleting it must redden the suite, not '
            . 'make the spawn-site assertions vacuously green.',
            $functionName,
        ));
    }
}
