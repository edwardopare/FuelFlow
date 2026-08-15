import { Eye, EyeOff } from 'lucide-react'
import { useState } from 'react'
import type { UseFormRegisterReturn } from 'react-hook-form'

type PasswordInputProps = {
  id: string
  autoComplete: string
  registration: UseFormRegisterReturn
  visibilityLabel?: string
}

export function PasswordInput({
  id,
  autoComplete,
  registration,
  visibilityLabel = 'password',
}: PasswordInputProps) {
  const [visible, setVisible] = useState(false)

  return (
    <div className="relative mt-2">
      <input
        autoComplete={autoComplete}
        className="form-input pr-12"
        id={id}
        type={visible ? 'text' : 'password'}
        {...registration}
      />
      <button
        aria-label={`${visible ? 'Hide' : 'Show'} ${visibilityLabel}`}
        className="absolute inset-y-0 right-0 grid w-12 place-items-center rounded-r-lg text-slate-400 hover:text-slate-700"
        onClick={() => setVisible((value) => !value)}
        type="button"
      >
        {visible ? (
          <EyeOff aria-hidden className="h-4 w-4" />
        ) : (
          <Eye aria-hidden className="h-4 w-4" />
        )}
      </button>
    </div>
  )
}
