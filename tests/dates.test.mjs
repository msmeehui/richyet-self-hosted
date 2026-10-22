import test from 'node:test';
import assert from 'node:assert/strict';
import { formatSnapshotDate, formatPriceTime, formatValuationDate, formatClosingDate } from '../public/lib/dates.mjs';

test('snapshot and price timestamps follow the selected device timezone across date boundaries', () => {
  const value = '2026-10-09T00:30:00Z';
  assert.equal(formatSnapshotDate(value, 'America/Los_Angeles'), '8 October 2026');
  assert.equal(formatPriceTime(value, 'America/Los_Angeles'), '8 Oct 2026, 17:30');
  assert.equal(formatSnapshotDate(value, 'Pacific/Kiritimati'), '9 October 2026');
  assert.equal(formatPriceTime(value, 'Pacific/Kiritimati'), '9 Oct 2026, 14:30');
  assert.equal(formatPriceTime('2026-10-08T17:30:00-07:00', 'Pacific/Kiritimati'), '9 Oct 2026, 14:30');
  const deviceZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  assert.equal(formatSnapshotDate(value), formatSnapshotDate(value, deviceZone));
  assert.equal(formatPriceTime(value), formatPriceTime(value, deviceZone));
});

test('fund valuation dates retain their civil day while nearby instants may change day', () => {
  const previousZone = process.env.TZ;
  try {
    for (const zone of ['America/Los_Angeles', 'Pacific/Kiritimati']) {
      process.env.TZ = zone;
      assert.equal(formatValuationDate('2026-10-09'), '9 Oct 2026');
      assert.equal(formatValuationDate('2024-02-29'), '29 Feb 2024');
      assert.equal(formatClosingDate('2026-10-09'), '9 Oct 2026');
    }
    assert.equal(formatSnapshotDate('2026-10-09T23:30:00Z'), '10 October 2026');
  } finally {
    if (previousZone === undefined) delete process.env.TZ;
    else process.env.TZ = previousZone;
  }
});

test('invalid or ambiguous dates stay unavailable instead of being normalized or assumed local', () => {
  for (const value of [null, undefined, 0, '', '2026-10-09', '2026-10-09T12:30:00',
    '2026-02-30T12:30:00Z', '2026-10-09T25:00:00Z', '2026-10-09T12:00:00+99:00']) {
    assert.equal(formatSnapshotDate(value), 'Unavailable');
    assert.equal(formatPriceTime(value), 'Unavailable');
  }
  for (const value of [null, undefined, 0, '', '2026-02-29', '2026-02-30', '2026-13-01',
    '2026-1-09', '09-10-2026', '2026-10-09T00:00:00Z']) {
    assert.equal(formatValuationDate(value), 'Unavailable');
    assert.equal(formatClosingDate(value), 'Unavailable');
  }
  assert.equal(formatSnapshotDate('2026-10-09T00:30:00Z', 'Invalid/Zone'), 'Unavailable');
});
