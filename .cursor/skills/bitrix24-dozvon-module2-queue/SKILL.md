---
name: bitrix24-dozvon-module2-queue
description: Build and debug Bitrix24 autodial module 2 queue generation for BP PHP blocks using lists 130/131 and city list 22. Use when the user mentions автонедозвон, модуль 2, bp_module2_generate_queue, список 130/131, CHASOVOY_POYAS, ранжирование очереди, or first-day call planning.
---

# Bitrix24 Dozvon Module 2 Queue

## Scope

Use this skill for the BP PHP block that generates autodial attempts in:
- master list `130`
- attempts list `131`
- city settings list `22`

Primary file:
- `bp_module2_generate_queue.txt`

## Hard Rules

- Treat list `22` working hours as Moscow time.
- Treat `CHASOVOY_POYAS` as the city UTC offset used to convert template slots from city local time to Moscow time.
- Generate queue only when master `STATUS = new`.
- Do not create duplicate attempts for the same master element.
- `day 0` is allowed only on the master item in list `130`.
- Attempts list `131` must still contain a full `day 1..10` cycle when the first real calling day starts later.

## City List 22 Parsing

Preferred source of working window:
- field like `Интервал работы КЦ (мск, чч:мм-чч:мм)`

Important:
- this field stores a range, not a single time
- parse both boundaries from one value, e.g. `10:00-19:00`
- first time = work start
- second time = work end

Fallback:
- if no interval field exists, resolve separate start/end fields by code/name heuristics

Failure symptom:
- if start and end become the same time, the window collapses and queue generation may end with `no future attempts available`

## Scheduling Rules

### Day 1

- first automatic attempt: `transition_to_nedozvon + 2 hours`
- next attempts: every `2 hours`
- maximum `4` attempts
- attempts must stay inside the Moscow working window
- last allowed attempt time is `work_end - 30 minutes`
- if an attempt does not fit, carry it to the next cycle day

### Days 2-10

- use the TZ schedule template:
  - day 2-3: `11:00, 13:00, 15:00, 17:00`
  - day 4: `11:00, 13:30, 16:00`
  - day 5-7: `11:00, 14:00`
  - day 8: `16:00`
  - day 9: `11:00`
  - day 10: `16:00`
- these template times are city-local
- convert them to Moscow time using `CHASOVOY_POYAS`
- after conversion, enforce the Moscow working window
- if a slot does not fit, move it to the next cycle day
- never place more than `4` attempts into one cycle day

## Master List 130 Rules

Set on generation:
- `STATUS = queue_generated`
- `CALLING_CONTROL = active`
- `CYCLE_DAYS_TOTAL = 10`
- `FIRST_ATTEMPT_AT`
- `NEXT_ATTEMPT_AT`
- `QUEUE_GENERATED_AT`
- `MODULE2_PROCESSED_AT`
- aggregate counters

Set `CYCLE_DAY_CURRENT`:
- `0` if no valid first-day attempts exist today
- `1` if day 1 already has at least one planned attempt today

Do not set `COMPLETED_AT` during queue generation.

Set `COMPLETED_AT` only when:
- a successful connection happened
- or all attempts are closed/cancelled
- or a stop scenario closes the cycle

## Ranking Rules

If list `131` has `RANZHIROVANIE`, recalculate ranking after queue creation.

Ranking order:
1. `cycle_day ASC`
2. lead creation time `DESC`
3. scheduled time `ASC`
4. element `ID ASC`

## Debug Workflow

When queue generation breaks:

1. Log resolved city settings:
   - city id
   - `CHASOVOY_POYAS`
   - work start/end
   - source field codes
2. Log `day 0/day 1` decision:
   - master creation time
   - first candidate time
   - work window
   - last allowed time
3. Log per-day planning summary:
   - scheduled count
   - carry-over count
4. Log final attempt count before the empty-plan check

Critical diagnostic:
- if `cityWorkStart == cityWorkEnd`, first inspect the interval parser for list `22`

## Common Pitfalls

- Parsing `Интервал работы КЦ` as a single time instead of a range
- Treating list `22` working hours as city-local instead of Moscow time
- Using `DATE_CREATE` from the master element when it is not the real `Недозвон` transition timestamp
- Dropping overflow attempts instead of carrying them to the next cycle day
- Renumbering real call days incorrectly and accidentally shrinking the 10-day cycle

## Output Expectations

For user-facing explanations, describe:
- why queue creation did or did not happen
- what `day 0` means
- how day 1 differs from days 2-10
- which field in list `22` supplied the working interval
