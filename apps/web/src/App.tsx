import { lazy, Suspense } from 'react'
import {
  createBrowserRouter,
  Navigate,
  RouterProvider,
} from 'react-router-dom'
import { LoadingScreen } from './components/LoadingScreen'
import { ProtectedLayout } from './components/ProtectedLayout'
import { VendorProtectedLayout } from './components/VendorProtectedLayout'

const AuditPage = lazy(() => import('./pages/AuditPage'))
const ChangePasswordPage = lazy(() => import('./pages/ChangePasswordPage'))
const DashboardPage = lazy(() => import('./pages/DashboardPage'))
const ForgotPasswordPage = lazy(() => import('./pages/ForgotPasswordPage'))
const LoginPage = lazy(() => import('./pages/LoginPage'))
const ModulePage = lazy(() => import('./pages/ModulePage'))
const ProfilePage = lazy(() => import('./pages/ProfilePage'))
const ResetPasswordPage = lazy(() => import('./pages/ResetPasswordPage'))
const StationsPage = lazy(() => import('./pages/StationsPage'))
const UsersPage = lazy(() => import('./pages/UsersPage'))
const VendorChangePasswordPage = lazy(
  () => import('./pages/VendorChangePasswordPage'),
)
const VendorCompaniesPage = lazy(() => import('./pages/VendorCompaniesPage'))
const VendorDashboardPage = lazy(() => import('./pages/VendorDashboardPage'))
const VendorLoginPage = lazy(() => import('./pages/VendorLoginPage'))

const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    path: '/forgot-password',
    element: <ForgotPasswordPage />,
  },
  {
    path: '/reset-password/:token',
    element: <ResetPasswordPage />,
  },
  {
    path: '/change-password',
    element: <ChangePasswordPage />,
  },
  {
    path: '/vendor/login',
    element: <VendorLoginPage />,
  },
  {
    path: '/vendor/change-password',
    element: <VendorChangePasswordPage />,
  },
  {
    element: <VendorProtectedLayout />,
    children: [
      {
        path: '/vendor',
        element: <VendorDashboardPage />,
      },
      {
        path: '/vendor/companies',
        element: <VendorCompaniesPage />,
      },
    ],
  },
  {
    element: <ProtectedLayout />,
    children: [
      {
        path: '/',
        element: <DashboardPage />,
      },
      {
        path: '/users',
        element: <UsersPage />,
      },
      {
        path: '/stations',
        element: <StationsPage />,
      },
      {
        path: '/profile',
        element: <ProfilePage />,
      },
      {
        path: '/audit',
        element: <AuditPage />,
      },
      {
        path: '/modules/:moduleSlug',
        element: <ModulePage />,
      },
    ],
  },
  {
    path: '*',
    element: <Navigate to="/" replace />,
  },
])

function App() {
  return (
    <Suspense fallback={<LoadingScreen label="Loading FuelFlow" />}>
      <RouterProvider router={router} />
    </Suspense>
  )
}

export default App
