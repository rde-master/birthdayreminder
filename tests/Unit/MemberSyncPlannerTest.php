<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use OCA\BirthdayReminder\Service\MemberSyncPlanner;
use PHPUnit\Framework\TestCase;

final class MemberSyncPlannerTest extends TestCase {
    private MemberSyncPlanner $planner;

    protected function setUp(): void {
        $this->planner = new MemberSyncPlanner();
    }

    private function existing(int $id, string $first, string $last, array $overrides = []): array {
        return array_merge([
            'id' => $id,
            'firstName' => $first,
            'lastName' => $last,
            'birthDay' => 15,
            'birthMonth' => 3,
            'birthYear' => 1990,
            'email' => 'anna@example.test',
            'disabled' => false,
            'remark' => null,
        ], $overrides);
    }

    private function row(string $first, string $last, array $overrides = []): array {
        return array_merge([
            'firstName' => $first,
            'lastName' => $last,
            'birthDay' => 15,
            'birthMonth' => 3,
            'birthYear' => 1990,
            'email' => 'anna@example.test',
        ], $overrides);
    }

    public function testNewNameIsInserted(): void {
        $plan = $this->planner->plan([], [$this->row('Anna', 'Muster')]);

        self::assertCount(1, $plan['inserts']);
        self::assertCount(0, $plan['updates']);
        self::assertCount(0, $plan['disables']);
        self::assertSame(0, $plan['unchangedCount']);
    }

    public function testUnchangedMemberIsLeftAlone(): void {
        $plan = $this->planner->plan([$this->existing(1, 'Anna', 'Muster')], [$this->row('Anna', 'Muster')]);

        self::assertCount(0, $plan['inserts']);
        self::assertCount(0, $plan['updates']);
        self::assertSame(1, $plan['unchangedCount']);
    }

    public function testMatchingIsCaseInsensitive(): void {
        $plan = $this->planner->plan([$this->existing(1, 'anna', 'MUSTER')], [$this->row('Anna', 'Muster')]);

        self::assertCount(0, $plan['inserts']);
        self::assertSame(1, $plan['unchangedCount']);
    }

    public function testChangedEmailProducesUpdate(): void {
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['email' => 'old@example.test'])],
            [$this->row('Anna', 'Muster', ['email' => 'new@example.test'])]
        );

        self::assertCount(1, $plan['updates']);
        self::assertSame('new@example.test', $plan['updates'][0]['email']);
        self::assertSame(1, $plan['updates'][0]['id']);
    }

    public function testChangedBirthdateProducesUpdate(): void {
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['birthDay' => 15, 'birthMonth' => 3])],
            [$this->row('Anna', 'Muster', ['birthDay' => 16, 'birthMonth' => 3])]
        );

        self::assertCount(1, $plan['updates']);
        self::assertSame(16, $plan['updates'][0]['birthDay']);
    }

    public function testMemberMissingFromCsvIsDisabledWithAutoRemark(): void {
        $plan = $this->planner->plan([$this->existing(1, 'Anna', 'Muster')], []);

        self::assertCount(1, $plan['disables']);
        self::assertSame(1, $plan['disables'][0]['id']);
        self::assertSame(MemberSyncPlanner::AUTO_DISABLE_REMARK, $plan['disables'][0]['remark']);
    }

    public function testAutoDisableRemarkIsAppendedToExistingRemark(): void {
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['remark' => 'Zahlt Beitrag per Lastschrift'])],
            []
        );

        self::assertSame(
            'Zahlt Beitrag per Lastschrift; ' . MemberSyncPlanner::AUTO_DISABLE_REMARK,
            $plan['disables'][0]['remark']
        );
    }

    public function testAutoDisableRemarkIsNotDuplicatedOnRepeatedImports(): void {
        $existing = $this->existing(1, 'Anna', 'Muster', ['remark' => MemberSyncPlanner::AUTO_DISABLE_REMARK]);
        $result = MemberSyncPlanner::appendAutoDisableRemark($existing['remark']);

        self::assertSame(MemberSyncPlanner::AUTO_DISABLE_REMARK, $result);
    }

    public function testAlreadyDisabledMemberIsNotTouchedAgain(): void {
        $plan = $this->planner->plan([$this->existing(1, 'Anna', 'Muster', ['disabled' => true])], []);

        self::assertCount(0, $plan['disables']);
    }

    public function testMatchesByEmailEvenWhenNameDiffers(): void {
        // Same person, contact was renamed - e-mail is the more stable identity.
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['email' => 'anna@example.test'])],
            [$this->row('Anne', 'Neuname', ['email' => 'anna@example.test'])]
        );

        self::assertCount(0, $plan['inserts']);
        self::assertCount(1, $plan['updates']);
        self::assertSame(1, $plan['updates'][0]['id']);
        self::assertSame('Anne', $plan['updates'][0]['firstName']);
        self::assertSame('Neuname', $plan['updates'][0]['lastName']);
    }

    public function testEmailMatchTakesPriorityOverNameMatch(): void {
        // Two existing members; the row's name matches #2 but its e-mail
        // matches #1 - e-mail wins, so #1 gets updated and #2 gets disabled
        // (not touched/left alone, which the old name-only matching would have done).
        $plan = $this->planner->plan(
            [
                $this->existing(1, 'Anna', 'Alt', ['email' => 'shared@example.test']),
                $this->existing(2, 'Bernd', 'Neu', ['email' => 'bernd@example.test']),
            ],
            [$this->row('Bernd', 'Neu', ['email' => 'shared@example.test'])]
        );

        self::assertCount(1, $plan['updates']);
        self::assertSame(1, $plan['updates'][0]['id']);
        self::assertCount(1, $plan['disables']);
        self::assertSame(2, $plan['disables'][0]['id']);
    }

    public function testFallsBackToNameMatchWhenRowHasNoEmail(): void {
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['email' => 'anna@example.test'])],
            [$this->row('Anna', 'Muster', ['email' => null])]
        );

        self::assertCount(0, $plan['inserts']);
        self::assertCount(1, $plan['updates']);
        self::assertNull($plan['updates'][0]['email']);
    }

    public function testEmailMatchingIsCaseInsensitive(): void {
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['email' => 'Anna@Example.test'])],
            [$this->row('Anna', 'Muster', ['email' => 'anna@example.test'])]
        );

        self::assertSame(1, $plan['unchangedCount']);
    }

    public function testDuplicateEmailInSameBatchUpdatesSameMemberInsteadOfInserting(): void {
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['email' => 'anna@example.test'])],
            [
                $this->row('Anna', 'Muster', ['email' => 'anna@example.test', 'birthDay' => 16]),
                $this->row('Anna', 'M.', ['email' => 'anna@example.test', 'birthDay' => 17]),
            ]
        );

        self::assertCount(0, $plan['inserts']);
        self::assertCount(2, $plan['updates']);
        self::assertSame(1, $plan['updates'][0]['id']);
        self::assertSame(1, $plan['updates'][1]['id']);
    }

    public function testReappearingDisabledMemberIsNotAutomaticallyReenabled(): void {
        // Explicitly documents the conservative behaviour: import only ever
        // disables, it never flips disabled back to false on its own. Field
        // values (here: unchanged) still sync normally - only the disabled
        // flag itself is left for a human to toggle back on.
        $plan = $this->planner->plan(
            [$this->existing(1, 'Anna', 'Muster', ['disabled' => true])],
            [$this->row('Anna', 'Muster')]
        );

        self::assertCount(0, $plan['updates']);
        self::assertCount(0, $plan['disables']);
        self::assertSame(1, $plan['unchangedCount']);

        // The plan itself carries no "re-enable" instruction - applying it
        // (see CsvImportService) only ever writes to inserts/updates/disables,
        // so a disabled member's disabled=true is never touched by import.
    }
}
