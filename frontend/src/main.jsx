import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import './styles/Forms.css'
import App from './App.jsx'

function mount() { createRoot(document.getElementById('root')).render(<App />) }
if (import.meta.env.MODE === 'certification') import('./certificationBridge').then(mount)
else mount()
