--TEST--
Test for bug #2252: Segfault in xdebug_branch_info_mark_reached due to stale last_branch_nr from filter mismatch
--SKIPIF--
<?php
require __DIR__ . '/../utils.inc';
check_reqs('PHP >= 8.1');
?>
--INI--
xdebug.mode=coverage
--FILE--
<?php
/* Root cause of bug #2252:
 *
 * Functions defined before xdebug_set_filter() is called are compiled with
 * filter_type_code_coverage == XDEBUG_FILTER_NONE, so xdebug_coverage_init_oparray()
 * leaves op_array->reserved[filter_offset] == 0.  The opcode handler therefore
 * fires for them regardless of any later xdebug_set_filter() call.
 *
 * When xdebug_set_filter() is then set to an include list that EXCLUDES those
 * functions, fse->filtered_code_coverage == 1 and start_of_function() is
 * skipped — so last_branch_nr[D] is never reset for those functions.
 *
 * If a prior function at the same stack depth D left last_branch_nr[D] at an
 * opcode index >= the excluded function's branch_info->size, the subsequent
 * access to branch_info->branches[last_branch_nr[D]] is out-of-bounds.
 *
 * The fix adds a bounds check in xdebug_branch_info_mark_reached(): when
 * last_branch_nr[D] >= branch_info->size, the stale value is reset to -1 and
 * the out-of-bounds access is prevented.
 */

/* This function has many branches.  It runs at stack depth D and leaves
 * last_branch_nr[D] at a high opcode index. */
function manyBranches(int $v): string
{
	if ($v === 1) return 'one';
	if ($v === 2) return 'two';
	if ($v === 3) return 'three';
	if ($v === 4) return 'four';
	if ($v === 5) return 'five';
	if ($v === 6) return 'six';
	if ($v === 7) return 'seven';
	if ($v === 8) return 'eight';
	if ($v === 9) return 'nine';
	return 'other';
}

/* This function has very few branches.  Both functions are compiled before any
 * filter is active, so reserved[filter_offset] == 0 for both.  After
 * xdebug_set_filter() excludes this function at runtime, start_of_function()
 * is skipped for it while the opcode handler still fires — leaving
 * last_branch_nr[D] stale from manyBranches(). */
function fewBranches(int $v): string
{
	return $v > 0 ? 'positive' : 'non-positive';
}

/* Step 1 – start path coverage so start_of_function() is wired up. */
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

/* Step 2 – call manyBranches to (a) prefill branch-info for the whole file
 * (including fewBranches) and (b) leave last_branch_nr[D] at a high value. */
manyBranches(5);

/* Step 3 – configure a filter that EXCLUDES the current file at runtime.
 * Because the functions were compiled before this call (XDEBUG_FILTER_NONE at
 * compile time), reserved[filter_offset] is still 0 and the opcode handler
 * continues to fire for them. */
xdebug_set_filter(
	XDEBUG_FILTER_CODE_COVERAGE,
	XDEBUG_PATH_INCLUDE,
	['/this/path/does/not/exist/']
);

/* Step 4 – call fewBranches at the same depth D.
 * opcode handler fires (reserved[filter_offset] == 0)
 * start_of_function is skipped (fse->filtered_code_coverage == 1)
 * last_branch_nr[D] is stale from manyBranches → OOB without the fix. */
fewBranches(1);

xdebug_stop_code_coverage();

/* Reaching this line means no crash occurred — the fix works. */
echo "Done\n";
?>
--EXPECT--
Done
