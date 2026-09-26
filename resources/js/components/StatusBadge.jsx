const STYLES = {
    active: 'bg-green-100 text-green-800',
    trialing: 'bg-blue-100 text-blue-800',
    past_due: 'bg-amber-100 text-amber-800',
    suspended: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-600',
    open: 'bg-blue-100 text-blue-800',
    paid: 'bg-green-100 text-green-800',
    void: 'bg-gray-100 text-gray-600',
};

function label(status) {
    return status
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export default function StatusBadge({ status }) {
    const style = STYLES[status] ?? 'bg-gray-100 text-gray-600';

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${style}`}>
            {label(status)}
        </span>
    );
}
