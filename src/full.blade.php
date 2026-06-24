@props(['size' => 'full'])

@php
    use Carbon\Carbon;
    use Illuminate\Support\Str;

    $tz = config('app.timezone');
    $currentTime = Carbon::now($tz);

    $names = collect(explode(',', (string) ($trmnl['plugin_settings']['custom_fields_values']['names'] ?? '')))
        ->map(fn ($name) => trim($name))
        ->filter()
        ->values();

    $showHeader = filter_var(
        $trmnl['plugin_settings']['custom_fields_values']['display_header'] ?? true,
        FILTER_VALIDATE_BOOLEAN
    );

    $sizeConfig = [
        'full' => [
            'height' => 460,
            'paddingY' => 6,
            'paddingX' => 12,
            'dayLabelHeight' => 16,
            'rowHeight' => 32,
            'groupGap' => 4,
            'rowGap' => 0,
            'timeWidth' => 48,
            'labelWidth' => 58,
            'gridGap' => 6,
            'dayFont' => 12,
            'timeFont' => 11,
            'titleFont' => 17,
            'calendarFont' => 9,
            'emptyFont' => 16,
            'titleLimit' => 84,
            'calendarLimit' => 12,
        ],
        'half_horizontal' => [
            'height' => 225,
            'paddingY' => 6,
            'paddingX' => 10,
            'dayLabelHeight' => 14,
            'rowHeight' => 28,
            'groupGap' => 3,
            'rowGap' => 0,
            'timeWidth' => 38,
            'labelWidth' => 42,
            'gridGap' => 4,
            'dayFont' => 11,
            'timeFont' => 9,
            'titleFont' => 13,
            'calendarFont' => 8,
            'emptyFont' => 14,
            'titleLimit' => 42,
            'calendarLimit' => 8,
        ],
        'half_vertical' => [
            'height' => 460,
            'paddingY' => 6,
            'paddingX' => 10,
            'dayLabelHeight' => 14,
            'rowHeight' => 28,
            'groupGap' => 3,
            'rowGap' => 0,
            'timeWidth' => 38,
            'labelWidth' => 42,
            'gridGap' => 4,
            'dayFont' => 11,
            'timeFont' => 9,
            'titleFont' => 13,
            'calendarFont' => 8,
            'emptyFont' => 14,
            'titleLimit' => 38,
            'calendarLimit' => 8,
        ],
        'quadrant' => [
            'height' => 235,
            'paddingY' => 5,
            'paddingX' => 8,
            'dayLabelHeight' => 13,
            'rowHeight' => 24,
            'groupGap' => 2,
            'rowGap' => 0,
            'timeWidth' => 34,
            'labelWidth' => 34,
            'gridGap' => 4,
            'dayFont' => 10,
            'timeFont' => 8,
            'titleFont' => 11,
            'calendarFont' => 7,
            'emptyFont' => 13,
            'titleLimit' => 20,
            'calendarLimit' => 6,
        ],
    ];

    $ui = $sizeConfig[$size] ?? $sizeConfig['full'];

    $calendarBuckets = collect($data)
        ->filter(fn ($value, $key) => Str::startsWith($key, 'IDX_') && is_array($value))
        ->sortKeysUsing(fn ($a, $b) => ((int) Str::after($a, 'IDX_')) <=> ((int) Str::after($b, 'IDX_')));

    $activeCalendarBuckets = $calendarBuckets->filter(function (array $bucket) {
        return collect($bucket['ical'] ?? [])->isNotEmpty();
    });

    $showCalendarLabels = $activeCalendarBuckets->count() > 1;

    $events = $calendarBuckets
        ->flatMap(function (array $bucket, string $key) use ($names, $tz, $currentTime) {
            $index = (int) Str::after($key, 'IDX_');
            $calendarName = $names->get($index) ?: 'CAL' . ($index + 1);

            return collect($bucket['ical'] ?? [])->map(function (array $event) use ($tz, $currentTime, $calendarName) {
                try {
                    $start = isset($event['DTSTART'])
                        ? Carbon::parse($event['DTSTART'])->setTimezone($tz)
                        : null;
                } catch (Exception $e) {
                    $start = null;
                }

                try {
                    $end = isset($event['DTEND'])
                        ? Carbon::parse($event['DTEND'])->setTimezone($tz)
                        : null;
                } catch (Exception $e) {
                    $end = null;
                }

                if (! $start) {
                    return null;
                }

                $isAllDay = isset($event['DTSTART']) && ! Str::contains((string) $event['DTSTART'], 'T');
                $effectiveEnd = $end ?? $start;

                if ($effectiveEnd->lt($currentTime)) {
                    return null;
                }

                return [
                    'calendar_name' => $calendarName,
                    'summary' => trim($event['SUMMARY'] ?? 'Untitled'),
                    'start' => $start,
                    'all_day' => $isAllDay,
                    'day_key' => $start->format('Y-m-d'),
                ];
            });
        })
        ->filter()
        ->sort(function (array $a, array $b) {
            if ($a['start']->isSameDay($b['start']) && $a['all_day'] !== $b['all_day']) {
                return $a['all_day'] ? -1 : 1;
            }

            return $a['start']->timestamp <=> $b['start']->timestamp;
        })
        ->values();

    $rows = collect();

    foreach ($events as $event) {
        $isNewDay = $rows->isEmpty() || $rows->last()['day_key'] !== $event['day_key'];

        if ($isNewDay) {
            $dateObj = $event['start']->copy();

            $rows->push([
                'type' => 'day',
                'day_key' => $event['day_key'],
                'label' => $dateObj->isToday()
                    ? 'Today'
                    : ($dateObj->isTomorrow() ? 'Tomorrow' : $dateObj->format('D, M j')),
            ]);
        }

        $rows->push([
            'type' => 'event',
            'day_key' => $event['day_key'],
            'time' => $event['all_day'] ? 'All day' : $event['start']->format('H:i'),
            'title' => $event['summary'],
            'calendar_name' => $event['calendar_name'],
        ]);
    }

    $availableHeight = $ui['height'] - ($ui['paddingY'] * 2);
    $usedHeight = 0;
    $visibleRows = collect();

    foreach ($rows as $row) {
        $rowHeight = $row['type'] === 'day'
            ? ($ui['dayLabelHeight'] + $ui['groupGap'])
            : ($ui['rowHeight'] + $ui['rowGap']);

        if (($usedHeight + $rowHeight) > $availableHeight) {
            break;
        }

        $visibleRows->push($row);
        $usedHeight += $rowHeight;
    }

    $scope = 'calendar-' . Str::random(6);
@endphp

<x-trmnl::view size="{{ $size }}">
    <style>
        #{{ $scope }} {
            --pad-y: {{ $ui['paddingY'] }}px;
            --pad-x: {{ $ui['paddingX'] }}px;
            --day-h: {{ $ui['dayLabelHeight'] }}px;
            --row-h: {{ $ui['rowHeight'] }}px;
            --group-gap: {{ $ui['groupGap'] }}px;
            --grid-gap: {{ $ui['gridGap'] }}px;
            --time-w: {{ $ui['timeWidth'] }}px;
            --label-w: {{ $ui['labelWidth'] }}px;
            --day-font: {{ $ui['dayFont'] }}px;
            --time-font: {{ $ui['timeFont'] }}px;
            --title-font: {{ $ui['titleFont'] }}px;
            --calendar-font: {{ $ui['calendarFont'] }}px;
            --empty-font: {{ $ui['emptyFont'] }}px;
        }

        #{{ $scope }}.calendar-wrap {
            height: 100%;
            width: 100%;
            min-width: 0;
            padding: var(--pad-y) var(--pad-x);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #fff;
            color: #000;
        }

        #{{ $scope }} .calendar-empty {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--empty-font);
            font-weight: 700;
            letter-spacing: -0.01em;
            text-align: center;
        }

        #{{ $scope }} .calendar-day {
            height: var(--day-h);
            display: flex;
            align-items: flex-end;
            width: 100%;
            min-width: 0;
            margin-bottom: var(--group-gap);
            padding-top: 2px;
            border-top: 1px solid #d9d9d9;
            font-size: var(--day-font);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            line-height: 1;
            color: #000;
        }

        #{{ $scope }} .calendar-event {
            width: 100%;
            min-width: 0;
            height: var(--row-h);
            display: grid;
            grid-template-columns: {{ $showCalendarLabels ? 'var(--time-w) minmax(0, 1fr) var(--label-w)' : 'var(--time-w) minmax(0, 1fr)' }};
            gap: var(--grid-gap);
            align-items: center;
            border-bottom: 1px solid #ececec;
            overflow: hidden;
        }

        #{{ $scope }} .calendar-time {
            min-width: 0;
            font-size: var(--time-font);
            font-weight: 700;
            line-height: 1;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            white-space: nowrap;
            color: #000;
        }

        #{{ $scope }} .calendar-title {
            min-width: 0;
            font-size: var(--title-font);
            font-weight: 700;
            line-height: 1.05;
            letter-spacing: -0.02em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #000;
        }

        #{{ $scope }} .calendar-label {
            min-width: 0;
            text-align: right;
            font-size: var(--calendar-font);
            font-weight: 700;
            line-height: 1;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #666;
        }
    </style>

    @if($showHeader)
        <div class="title_bar">
            <span class="title">Calendar</span>
            <span class="instance">{{ $visibleRows->where('type', 'event')->count() }} upcoming</span>
        </div>
    @endif

    <x-trmnl::layout class="layout--col w--full" style="height:100%; width:100%; padding:0; gap:0;">
        <div id="{{ $scope }}" class="calendar-wrap">
            @if($visibleRows->where('type', 'event')->isEmpty())
                <div class="calendar-empty">
                    No upcoming events
                </div>
            @else
                @foreach($visibleRows as $row)
                    @if($row['type'] === 'day')
                        <div class="calendar-day">
                            {{ $row['label'] }}
                        </div>
                    @else
                        <article class="calendar-event">
                            <div class="calendar-time">
                                {{ $row['time'] }}
                            </div>

                            <div class="calendar-title">
                                {{ Str::limit($row['title'], $ui['titleLimit']) }}
                            </div>

                            @if($showCalendarLabels)
                                <div class="calendar-label">
                                    {{ Str::limit($row['calendar_name'], $ui['calendarLimit']) }}
                                </div>
                            @endif
                        </article>
                    @endif
                @endforeach
            @endif
        </div>
    </x-trmnl::layout>
</x-trmnl::view>