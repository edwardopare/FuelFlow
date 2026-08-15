type LoadingScreenProps = {
  label?: string
}

export function LoadingScreen({ label = 'Loading' }: LoadingScreenProps) {
  return (
    <div className="grid min-h-screen place-items-center bg-slate-50">
      <div className="flex items-center gap-3 text-sm font-medium text-slate-600">
        <span
          aria-hidden="true"
          className="h-5 w-5 animate-spin rounded-full border-2 border-blue-600 border-r-transparent"
        />
        <span>{label}</span>
      </div>
    </div>
  )
}
