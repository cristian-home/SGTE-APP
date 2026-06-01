import { X } from 'lucide-react';
import { type KeyboardEvent, useId, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface BillingGroupsInputProps {
    value: string[];
    onChange: (next: string[]) => void;
    id?: string;
    disabled?: boolean;
    invalid?: boolean;
    /**
     * Per-tag max length. Tags exceeding this are silently rejected on add.
     * Mirrors the backend validation (`billing_groups.*` → max:50).
     */
    maxTagLength?: number;
    placeholder?: string;
}

const DEFAULT_PLACEHOLDER = 'Escribe y presiona Enter o coma';

export default function BillingGroupsInput({
    value,
    onChange,
    id,
    disabled,
    invalid,
    maxTagLength = 50,
    placeholder = DEFAULT_PLACEHOLDER,
}: BillingGroupsInputProps) {
    const fallbackId = useId();
    const inputId = id ?? `billing-groups-input-${fallbackId}`;
    const [draft, setDraft] = useState('');
    const inputRef = useRef<HTMLInputElement | null>(null);

    const commitDraft = () => {
        const trimmed = draft.trim();
        setDraft('');
        if (trimmed === '' || trimmed.length > maxTagLength) {
            return;
        }
        if (value.includes(trimmed)) {
            return;
        }
        onChange([...value, trimmed]);
    };

    const removeAt = (index: number) => {
        if (disabled) return;
        onChange(value.filter((_, i) => i !== index));
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            commitDraft();
            return;
        }
        if (event.key === 'Backspace' && draft === '' && value.length > 0) {
            event.preventDefault();
            removeAt(value.length - 1);
        }
    };

    return (
        <div
            className={cn(
                'flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs',
                'focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                'transition-[color,box-shadow]',
                disabled && 'cursor-not-allowed opacity-50',
                invalid &&
                    'border-destructive ring-destructive/20 focus-within:ring-destructive/40',
            )}
            onClick={() => {
                if (!disabled) inputRef.current?.focus();
            }}
        >
            {value.map((tag, index) => (
                <Badge
                    key={`${tag}-${index}`}
                    variant="secondary"
                    className="gap-1 pr-1 pl-2"
                >
                    <span className="max-w-[200px] truncate">{tag}</span>
                    {!disabled && (
                        <button
                            type="button"
                            onClick={(event) => {
                                event.stopPropagation();
                                removeAt(index);
                            }}
                            className="rounded-sm text-muted-foreground transition-colors hover:text-foreground focus:text-foreground focus:outline-none"
                            aria-label={`Eliminar grupo ${tag}`}
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </Badge>
            ))}
            <input
                ref={inputRef}
                id={inputId}
                type="text"
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={commitDraft}
                disabled={disabled}
                aria-invalid={invalid || undefined}
                aria-label="Grupos de facturación"
                placeholder={value.length === 0 ? placeholder : ''}
                maxLength={maxTagLength}
                className="min-w-32 flex-1 bg-transparent text-foreground outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
            />
        </div>
    );
}
