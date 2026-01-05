@props(['houseguest'])

<div class="flex flex-col items-center gap-2 rounded-xl border border-neutral-200 bg-white p-3 text-center dark:border-neutral-700 dark:bg-zinc-900">
    <div @class([$houseguest->is_active ? null : 'filter sepia'])>
        <flux:avatar
            :src="$houseguest->avatar_url ? asset('storage/'.$houseguest->avatar_url) : null"
            :name="$houseguest->name"
            size="lg"
            circle
        />
    </div>

    <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $houseguest->name }}</div>
</div>
