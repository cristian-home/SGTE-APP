import { Area, AreaChart, ResponsiveContainer, Tooltip } from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { LucideIcon } from 'lucide-react';

export type KpiTone = 'success' | 'warning' | 'destructive' | 'muted';

export type KpiBreakdown = {
    label: string;
    value: number;
    tone: KpiTone;
};

export type KpiSparklinePoint = {
    date: string;
    count: number;
};

/**
 * Stat card used in the dashboard header. When `sparkline` is provided
 * renders a low-contrast 14-day area chart below the breakdown badges
 * — kept to ~48px tall so it never competes with the headline number.
 *
 * Tone → shadcn Badge variant: success/warning/destructive/muted →
 * default/secondary/destructive/outline. Centralized here so all 4 KPI
 * cards stay visually consistent.
 */
export function KpiCard({
    icon: Icon,
    title,
    total,
    breakdown,
    sparkline,
}: {
    icon: LucideIcon;
    title: string;
    total: number;
    breakdown: KpiBreakdown[];
    sparkline?: KpiSparklinePoint[];
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <CardTitle className="text-sm font-medium text-muted-foreground">
                        {title}
                    </CardTitle>
                    <Icon
                        className="size-5 text-muted-foreground"
                        aria-hidden
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div className="text-3xl font-semibold tabular-nums">
                    {total}
                </div>
                <div className="mt-2 flex flex-wrap gap-2">
                    {breakdown.map((item) => (
                        <Badge
                            key={item.label}
                            variant={badgeVariant(item.tone)}
                        >
                            {item.label}: {item.value}
                        </Badge>
                    ))}
                </div>
                {sparkline && sparkline.length > 0 && (
                    <div className="mt-3 h-12 w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart
                                data={sparkline}
                                margin={{
                                    top: 4,
                                    right: 0,
                                    bottom: 0,
                                    left: 0,
                                }}
                            >
                                <defs>
                                    <linearGradient
                                        id={`kpi-spark-${title}`}
                                        x1="0"
                                        y1="0"
                                        x2="0"
                                        y2="1"
                                    >
                                        <stop
                                            offset="5%"
                                            stopColor="currentColor"
                                            stopOpacity={0.4}
                                        />
                                        <stop
                                            offset="95%"
                                            stopColor="currentColor"
                                            stopOpacity={0}
                                        />
                                    </linearGradient>
                                </defs>
                                <Tooltip
                                    cursor={{
                                        stroke: 'currentColor',
                                        strokeOpacity: 0.2,
                                    }}
                                    contentStyle={{
                                        background: 'var(--popover)',
                                        border: '1px solid var(--border)',
                                        borderRadius: 6,
                                        fontSize: 12,
                                        padding: '4px 8px',
                                    }}
                                    labelStyle={{
                                        color: 'var(--muted-foreground)',
                                    }}
                                />
                                <Area
                                    type="monotone"
                                    dataKey="count"
                                    stroke="currentColor"
                                    strokeWidth={1.5}
                                    fill={`url(#kpi-spark-${title})`}
                                    isAnimationActive={false}
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function badgeVariant(
    tone: KpiTone,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (tone) {
        case 'success':
            return 'default';
        case 'warning':
            return 'secondary';
        case 'destructive':
            return 'destructive';
        case 'muted':
            return 'outline';
    }
}
