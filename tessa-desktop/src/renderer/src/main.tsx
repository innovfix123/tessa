import React from 'react'
import ReactDOM from 'react-dom/client'
import { HashRouter } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import App from './App'
import { AuthProvider } from './contexts/AuthContext'
import { VoiceProvider } from './contexts/VoiceContext'
import './assets/index.css'

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <HashRouter>
      <AuthProvider>
        <VoiceProvider>
        <App />
        <Toaster
          position="top-right"
          toastOptions={{
            className: '!bg-zinc-800 !text-zinc-100 !border !border-zinc-700',
            duration: 4000
          }}
        />
        </VoiceProvider>
      </AuthProvider>
    </HashRouter>
  </React.StrictMode>
)
