const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('__AIMS_API_BASE__', 'http://127.0.0.1:18765/api')
contextBridge.exposeInMainWorld('aimsDesktop', {
  isDesktop: true,
  getLicenceStatus: () => ipcRenderer.invoke('aims:licence-status'),
  activateLicence: () => ipcRenderer.invoke('aims:activate-licence'),
  getUpdateStatus: () => ipcRenderer.invoke('aims:update-status'),
  checkForUpdates: () => ipcRenderer.invoke('aims:check-updates'),
  onUpdateStatus: (callback) => {
    const listener = (_event, status) => callback(status)
    ipcRenderer.on('aims:update-status', listener)
    return () => ipcRenderer.removeListener('aims:update-status', listener)
  },
})
