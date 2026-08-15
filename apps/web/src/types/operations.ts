export type OperationalRecord = {
  id: string
  [key: string]: unknown
}

export type ListResponse = {
  data: OperationalRecord[]
}

export type DashboardSummary = {
  currency: 'GHS'
  business_date: string
  sales_value: number
  sales_litres: number
  transactions: number
  authorized_stations: number
  pending_purchase_orders: number
  pending_deliveries: number
  low_tanks: number
  total_stock_litres: number
  supplier_count: number
  tanks: Array<{
    id: string
    name: string
    station_name: string
    product_name: string
    book_stock_litres: number
    capacity_litres: number
    stock_percentage: number
  }>
  sales_by_product: Array<{
    product_name: string
    litres: number
    amount: number
  }>
  recent_sales: Array<{
    id: string
    receipt_number: string
    station_name: string
    product_name: string
    litres: number
    amount: number
    sold_at: string
  }>
  daily_attendant_sales: Array<{
    date: string
    attendant_id: string
    attendant_name: string
    started_at: string | null
    closed_at: string | null
    amount: number
  }>
  latest_reconciliation: {
    business_date: string
    status: string
    tank_variance_litres: number
    cash_variance: number
  } | null
}

export type ReportResult = {
  type: string
  currency: 'GHS'
  generated_at: string
  columns: string[]
  rows: Array<Record<string, string | number | null>>
}
