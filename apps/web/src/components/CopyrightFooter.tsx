type CopyrightFooterProps = {
  className?: string
}

const currentYear = new Date().getFullYear()

export function CopyrightFooter({
  className = '',
}: CopyrightFooterProps) {
  return (
    <footer
      className={`text-center text-xs text-slate-500 ${className}`.trim()}
    >
      © {currentYear} S4F. All rights reserved.
    </footer>
  )
}
