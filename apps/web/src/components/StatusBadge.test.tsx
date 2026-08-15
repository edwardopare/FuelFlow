import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { StatusBadge } from './StatusBadge'

describe('StatusBadge', () => {
  it('renders its status label', () => {
    render(<StatusBadge label="pending first login" tone="warning" />)

    expect(screen.getByText('pending first login')).toBeVisible()
  })
})
