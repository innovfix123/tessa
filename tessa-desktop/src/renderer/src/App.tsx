import { Routes, Route, Navigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import Layout from '@/components/Layout'
import Login from '@/pages/Login'
import Dashboard from '@/pages/Dashboard'
import Meetings from '@/pages/Meetings'
import TessaChat from '@/pages/TessaChat'
import Tasks from '@/pages/Tasks'
import DailyReports from '@/pages/DailyReports'
import Kpi from '@/pages/Kpi'
import MarketingKpi from '@/pages/MarketingKpi'
import Calendar from '@/pages/Calendar'
import Agile from '@/pages/Agile'
import Escalations from '@/pages/Escalations'
import SignOff from '@/pages/SignOff'
import Releases from '@/pages/Releases'
import Tickets from '@/pages/Tickets'
import Profile from '@/pages/Profile'
import Leave from '@/pages/Leave'
import Employees from '@/pages/Employees'
import Templates from '@/pages/Templates'
import Scripts from '@/pages/Scripts'
import Mission from '@/pages/Mission'
import Revenue from '@/pages/Revenue'
import MetaAds from '@/pages/MetaAds'
import GoogleAds from '@/pages/GoogleAds'
import Invoices from '@/pages/Invoices'
import Admin from '@/pages/Admin'
import OrgChart from '@/pages/OrgChart'
import { Loader } from '@/components/ui'

function ProtectedRoute({ children }: { children: JSX.Element }): JSX.Element {
  const { user, loading } = useAuth()

  if (loading) {
    return (
      <div className="flex h-screen items-center justify-center bg-surface-0">
        <Loader size="lg" label="Loading Tessa..." />
      </div>
    )
  }

  if (!user) return <Navigate to="/login" replace />
  return children
}

export default function App(): JSX.Element {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        <Route index element={<Dashboard />} />
        <Route path="meetings" element={<Meetings />} />
        <Route path="tessa" element={<TessaChat />} />
        <Route path="tasks" element={<Tasks />} />
        <Route path="calendar" element={<Calendar />} />
        <Route path="daily-reports" element={<DailyReports />} />
        <Route path="kpi" element={<Kpi />} />
        <Route path="marketing-kpi" element={<MarketingKpi />} />
        <Route path="escalations" element={<Escalations />} />
        <Route path="signoff" element={<SignOff />} />
        <Route path="org" element={<OrgChart />} />
        <Route path="templates" element={<Templates />} />
        <Route path="releases" element={<Releases />} />
        <Route path="scripts" element={<Scripts />} />
        <Route path="tickets" element={<Tickets />} />
        <Route path="invoices" element={<Invoices />} />
        <Route path="meta-ads" element={<MetaAds />} />
        <Route path="google-ads" element={<GoogleAds />} />
        <Route path="mission" element={<Mission />} />
        <Route path="employees" element={<Employees />} />
        <Route path="profile" element={<Profile />} />
        <Route path="leave" element={<Leave />} />
        <Route path="agile" element={<Agile />} />
        <Route path="admin" element={<Admin />} />
        <Route path="revenue" element={<Revenue />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
