import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/admin-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ToggleRight } from 'lucide-react';
import { useState } from 'react';

interface Props {
    flags: Record<string, boolean>;
}

const FLAG_DESCRIPTIONS: Record<string, { label: string; description: string }> = {
    dates: {
        label: 'Dates',
        description: 'Enable scheduled dates and due dates on items.',
    },
    delegation: {
        label: 'Delegation',
        description: 'Allow users to assign items to connected users.',
    },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Feature Flags', href: '/admin/feature-flags' },
];

export default function FeatureFlagsIndex({ flags }: Props) {
    const [localFlags, setLocalFlags] = useState<Record<string, boolean>>(flags);
    const [saving, setSaving] = useState(false);
    const flash = usePage().props.flash as { success?: string } | undefined;

    const hasChanges = Object.keys(localFlags).some((key) => localFlags[key] !== flags[key]);

    const handleToggle = (key: string) => {
        setLocalFlags((prev) => ({ ...prev, [key]: !prev[key] }));
    };

    const handleSave = () => {
        setSaving(true);
        router.put('/admin/feature-flags', localFlags, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    const handleReset = () => {
        setLocalFlags(flags);
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Feature Flags" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Feature Flags</h1>
                        <p className="text-sm text-muted-foreground">
                            Control which features are available globally. Disabling a flag here prevents all users from using that feature,
                            regardless of their personal settings.
                        </p>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div className="grid gap-4">
                    {Object.entries(localFlags).map(([key, enabled]) => {
                        const meta = FLAG_DESCRIPTIONS[key] ?? { label: key, description: '' };
                        const changed = enabled !== flags[key];

                        return (
                            <Card key={key}>
                                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                    <div className="flex flex-col gap-1.5">
                                        <CardTitle className="flex items-center gap-2">
                                            <ToggleRight className="h-4 w-4" />
                                            {meta.label}
                                            {changed && (
                                                <Badge variant="secondary" className="text-xs">
                                                    unsaved
                                                </Badge>
                                            )}
                                        </CardTitle>
                                        <CardDescription>{meta.description}</CardDescription>
                                    </div>
                                    <Button
                                        variant={enabled ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => handleToggle(key)}
                                        className="min-w-[90px]"
                                    >
                                        {enabled ? 'Enabled' : 'Disabled'}
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground">Status:</span>
                                        <Badge variant={enabled ? 'default' : 'destructive'}>{enabled ? 'Active' : 'Inactive'}</Badge>
                                        <span className="text-muted-foreground">·</span>
                                        <span className="font-mono text-xs text-muted-foreground">{key}</span>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {hasChanges && (
                    <div className="sticky bottom-4 flex justify-end gap-2 rounded-lg border bg-background p-4 shadow-lg">
                        <Button variant="outline" onClick={handleReset}>
                            Discard Changes
                        </Button>
                        <Button onClick={handleSave} disabled={saving}>
                            {saving ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
