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
            'paddingY' => 10,
            'paddingX' => 14,
            'dayLabelHeight' => 18,
            'rowHeight' => 34,
            'groupGap' => 8,
            'timeWidth' => 56,
            'labelWidth' => 68,
            'dayFont' => 11,
            'timeFont' => 10,
            'titleFont' => 15,
            'calendarFont' => 9,
            'titleLimit' => 72,
            'calendarLimit' => 12,
        ],
        'half_horizontal' => [
            'height' => 225,
            'paddingY' => 8,
            'paddingX' => 12,
            'dayLabelHeight' => 16,
            'rowHeight' => 30,
            'groupGap' => 6,
            'timeWidth' => 52,
            'labelWidth' => 62,
            'dayFont' => 10,
            'timeFont' => 9,
            'titleFont' => 13,
            'calendarFont' => 8,
            'titleLimit' => 52,
            'calendarLimit' => 10,
        ],
        'half_vertical' => [
            'height' => 460,
            'paddingY' => 8,
            'paddingX' => 12,
            'dayLabelHeight' => 16,
            'rowHeight' => 30,
            'groupGap' => 6,
            'timeWidth' => 52,
            'labelWidth' => 62,
            'dayFont' => 10,
            'timeFont' => 9,
            'titleFont' => 13,
            'calendarFont' => 8,
            'titleLimit' => 46,
            'calendarLimit' => 10,
        ],
        'quadrant' => [
            'height' => 235,
            'paddingY' => 7,
            'paddingX' => 10,
            'dayLabelHeight' => 15,
            'rowHeight' => 26,
            'groupGap' => 5,
            'timeWidth' => 46,
            'labelWidth' => 52,
            'dayFont' => 9,
            'timeFont' => 8,
            'titleFont' => 11,
            'calendarFont' => 8,
            'titleLimit' => 28,
            'calendarLimit' => 8,
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
            $calendarName = $names->get($index);

            if (! $calendarName) {
                $calendarName = 'CAL' . ($index + 1);
            }

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
            : $ui['rowHeight'];

        if (($usedHeight + $rowHeight) > $availableHeight) {
            break;
        }

        $visibleRows->push($row);
        $usedHeight += $rowHeight;
    }
@endphp

<x-trmnl::view size="{{ $size }}">
    @if($showHeader)
        <div class="title_bar">
            <span class="title">Calendar</span>
            <span class="instance">{{ $visibleRows->where('type', 'event')->count() }} upcoming</span>
        </div>
    @endif

    <x-trmnl::layout class="layout--col" style="height:100%; width:100%; padding:0; gap:0;">
        <div style="
            height:100%;
            width:100%;
            padding:{{ $ui['paddingY'] }}px {{ $ui['paddingX'] }}px;
            overflow:hidden;
            display:flex;
            flex-direction:column;
            background:#fff;
            color:#000;
        ">
            @if($visibleRows->where('type', 'event')->isEmpty())
                <div style="
                    height:100%;
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    font-size:14px;
                    font-weight:700;
                    letter-spacing:-0.01em;
                ">
                    No upcoming events
                </div>
            @else
                @foreach($visibleRows as $row)
                    @if($row['type'] === 'day')
                        <div style="
                            height:{{ $ui['dayLabelHeight'] }}px;
                            display:flex;
                            align-items:center;
                            margin-bottom:{{ $ui['groupGap'] }}px;
                            font-size:{{ $ui['dayFont'] }}px;
                            font-weight:700;
                            text-transform:uppercase;
                            letter-spacing:0.08em;
                            color:#000;
                            border-top:1px solid #d9d9d9;
                            padding-top:4px;
                        ">
                            {{ $row['label'] }}
                        </div>
                    @else
                        <article style="
                            height:{{ $ui['rowHeight'] }}px;
                            display:grid;
                            grid-template-columns:{{ $showCalendarLabels ? $ui['timeWidth'].'px 1fr '.$ui['labelWidth'].'px' : $ui['timeWidth'].'px 1fr' }};
                            gap:8px;
                            align-items:center;
                            border-bottom:1px solid #ebebeb;
                            overflow:hidden;
                        ">
                            <div style="
                                font-size:{{ $ui['timeFont'] }}px;
                                font-weight:700;
                                line-height:1;
                                letter-spacing:0.04em;
                                text-transform:uppercase;
                                white-space:nowrap;
                                color:#000;
                            ">
                                {{ $row['time'] }}
                            </div>

                            <div style="
                                min-width:0;
                                font-size:{{ $ui['titleFont'] }}px;
                                font-weight:700;
                                line-height:1;
                                letter-spacing:-0.02em;
                                white-space:nowrap;
                                overflow:hidden;
                                text-overflow:ellipsis;
                                color:#000;
                            ">
                                {{ Str::limit($row['title'], $ui['titleLimit']) }}
                            </div>

                            @if($showCalendarLabels)
                                <div style="
                                    min-width:0;
                                    text-align:right;
                                    font-size:{{ $ui['calendarFont'] }}px;
                                    font-weight:700;
                                    line-height:1;
                                    letter-spacing:0.08em;
                                    text-transform:uppercase;
                                    white-space:nowrap;
                                    overflow:hidden;
                                    text-overflow:ellipsis;
                                    color:#666;
                                ">
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