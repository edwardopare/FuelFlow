export type RoleAssignment = {
  id: string
  role: {
    id: string
    name: string
    slug: RoleSlug
    scope: 'organization' | 'station'
  }
  station_id: string | null
}

export type RoleSlug =
  | 'administrator'
  | 'owner'
  | 'station_manager'
  | 'cashier_attendant'
  | 'accountant'
  | 'auditor'

export type Role = {
  id: string
  name: string
  slug: RoleSlug
  scope: 'organization' | 'station'
  description: string | null
}

export type Station = {
  id: string
  code: string
  station_number?: string | null
  name: string
  registration_number?: string | null
  phone?: string | null
  address?: string | null
  timezone: string
  currency: 'GHS'
  is_active: boolean
  manager?: {
    id: string
    name: string
    email: string
    phone: string | null
    status: User['status']
  } | null
  assigned_users?: Array<{
    id: string
    name: string
    email: string
    phone: string | null
    status: User['status']
    roles: string[]
  }>
  assigned_users_count?: number
}

export type User = {
  id: string
  name: string
  email: string
  phone: string | null
  status: 'pending_first_login' | 'active' | 'inactive' | 'locked'
  must_change_password: boolean
  organization: {
    id: string
    name: string
    currency: 'GHS'
    timezone: string
  }
  stations: Station[]
  role_assignments: RoleAssignment[]
  last_authenticated_at: string | null
  created_at: string
}

export type ResourceResponse<T> = {
  data: T
}

export type PaginatedResponse<T> = {
  data: T[]
  links: Record<string, string | null>
  meta: {
    current_page?: number
    last_page?: number
    per_page?: number
    total?: number
    next_cursor?: string | null
    prev_cursor?: string | null
  }
}

export type AuditEvent = {
  id: string
  action: string
  actor: { id: string; name: string } | null
  station_id: string | null
  subject_type: string | null
  subject_id: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  reason: string | null
  request_id: string | null
  created_at: string
}

export type VendorUser = {
  id: string
  name: string
  email: string
  phone: string | null
  role: 'super_user'
  status: 'pending_first_login' | 'active' | 'inactive' | 'locked'
  must_change_password: boolean
  last_authenticated_at: string | null
  created_at: string
}

export type VendorRole = Role & {
  permissions: Array<{
    name: string
    description: string | null
  }>
}

export type VendorOrganization = {
  id: string
  name: string
  slug: string
  status: 'active' | 'suspended' | 'expired' | 'license_deactivated'
  registration_number: string | null
  contact_email: string
  phone: string
  address: string
  currency: 'GHS'
  timezone: string
  activated_at: string | null
  suspended_at: string | null
  created_at: string
  license: {
    status: 'active' | 'expired' | 'license_deactivated'
    duration: number
    unit: 'months' | 'years'
    started_at: string | null
    expires_at: string | null
    deactivated_at: string | null
    deactivation_reason: string | null
    remaining_days: number | null
  }
  users_count: number
  stations_count: number
  stations?: Array<{
    id: string
    name: string
    code: string
    station_number: string | null
    phone: string | null
    address: string | null
    is_active: boolean
  }>
  accounts?: Array<{
    id: string
    name: string
    email: string
    phone: string | null
    status: User['status']
    must_change_password: boolean
    station: { id: string; name: string } | null
    role: {
      slug: RoleSlug
      name: string
      scope: 'organization' | 'station'
    } | null
    created_at: string
  }>
}

export type VendorDashboard = {
  metrics: {
    companies: number
    active_companies: number
    suspended_companies: number
    expired_licenses: number
    deactivated_licenses: number
    licenses_expiring_soon: number
    accounts: number
    operating_stations: number
  }
  recent_companies: VendorOrganization[]
  recent_activity: Array<{
    id: string
    action: string
    actor: { id: string; name: string } | null
    subject_id: string | null
    reason: string | null
    created_at: string
  }>
}
