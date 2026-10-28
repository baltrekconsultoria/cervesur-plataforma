import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router';
import { App } from './app/App';
import { AppProvider } from './app/AppProvider';
import './styles/app.css';

const container = document.getElementById('root');
if (!container) throw new Error('No se encontró el contenedor #root.');

createRoot(container).render(
  <StrictMode>
    <BrowserRouter>
      <AppProvider>
        <App />
      </AppProvider>
    </BrowserRouter>
  </StrictMode>
);
