import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Target = { id: number; name: string; is_default: boolean };
export type TargetsResponse = {
    targets: Target[];
    can_create: boolean;
    limit: number | null;
};

const NEW = '__new__';

/**
 * Picks which list (collection / wishlist) to add to: select an existing one, or
 * "New …" to name a fresh one (only offered when the tier allows another). Hidden
 * entirely when the user has just one list and can't create more — it just uses
 * that one. Lazy-loads the targets from `endpoint` when first shown.
 *
 * Reports the choice via `onChange(id | null, newName | null)`: an id for an
 * existing list, or a name (id null) to create one.
 */
export function ListTargetPicker({
    endpoint,
    label,
    open,
    onChange,
    preloaded,
}: {
    endpoint: string;
    label: string;
    /** Fetch only once this is true (i.e. the parent dialog is open). */
    open: boolean;
    onChange: (id: number | null, newName: string | null) => void;
    /** Skip the fetch when the caller already has the targets. */
    preloaded?: TargetsResponse;
}) {
    const [data, setData] = useState<TargetsResponse | null>(preloaded ?? null);
    // Seed the displayed selection from preloaded data (display only — an
    // unchanged selection falls back to the default on the server).
    const [value, setValue] = useState<string>(() => {
        const def =
            preloaded?.targets.find((t) => t.is_default) ??
            preloaded?.targets[0];

        return def ? String(def.id) : '';
    });
    const [newName, setNewName] = useState('');

    useEffect(() => {
        if (!open || data) {
            return;
        }

        const apply = (d: TargetsResponse) => {
            setData(d);
            const def = d.targets.find((t) => t.is_default) ?? d.targets[0];

            if (def) {
                setValue(String(def.id));
                onChange(def.id, null);
            }
        };

        fetch(endpoint, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then(apply)
            .catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, endpoint]);

    const choose = (v: string) => {
        setValue(v);

        if (v === NEW) {
            onChange(null, newName.trim() || null);
        } else {
            onChange(Number(v), null);
        }
    };

    const typeName = (name: string) => {
        setNewName(name);

        if (value === NEW) {
            onChange(null, name.trim() || null);
        }
    };

    if (!data) {
        return (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" /> Loading {label}s…
            </div>
        );
    }

    // One list and no room for more — nothing to choose; the default is used.
    if (data.targets.length <= 1 && !data.can_create) {
        return null;
    }

    return (
        <div className="grid gap-2">
            <Label className="capitalize">{label}</Label>
            <Select value={value} onValueChange={choose}>
                <SelectTrigger>
                    <SelectValue placeholder={`Choose a ${label}`} />
                </SelectTrigger>
                <SelectContent>
                    {data.targets.map((t) => (
                        <SelectItem key={t.id} value={String(t.id)}>
                            {t.name}
                            {t.is_default ? ' (default)' : ''}
                        </SelectItem>
                    ))}
                    {data.can_create && (
                        <SelectItem value={NEW}>+ New {label}…</SelectItem>
                    )}
                </SelectContent>
            </Select>
            {value === NEW && (
                <Input
                    value={newName}
                    onChange={(e) => typeName(e.target.value)}
                    placeholder={`New ${label} name`}
                    maxLength={60}
                    autoFocus
                />
            )}
        </div>
    );
}
