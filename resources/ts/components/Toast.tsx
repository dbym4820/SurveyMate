import { useEffect } from 'react';
import { CheckCircle, XCircle, AlertCircle, X } from 'lucide-react';

export type ToastType = 'success' | 'error' | 'info';

interface ToastProps {
  message: string;
  type?: ToastType;
  onClose: () => void;
  duration?: number;
}

export default function Toast({ message, type = 'info', onClose, duration = 4000 }: ToastProps): JSX.Element {
  useEffect(() => {
    if (duration > 0) {
      const timer = setTimeout(onClose, duration);
      return () => clearTimeout(timer);
    }
  }, [duration, onClose]);

  const icons = {
    success: <CheckCircle className="w-5 h-5 text-green-500" />,
    error: <XCircle className="w-5 h-5 text-red-500" />,
    info: <AlertCircle className="w-5 h-5 text-blue-500" />,
  };

  const bgColors = {
    success: 'bg-green-50 border-green-200',
    error: 'bg-red-50 border-red-200',
    info: 'bg-blue-50 border-blue-200',
  };

  const textColors = {
    success: 'text-green-800',
    error: 'text-red-800',
    info: 'text-blue-800',
  };

  return (
    <>
      {/* Backdrop - click to dismiss */}
      <div
        className="fixed inset-0 z-40"
        onClick={onClose}
      />
      {/* Toast */}
      <div className="fixed top-4 right-4 z-50 animate-slide-in">
        <div
          className={`flex items-center gap-3 px-4 py-3 rounded-xl border shadow-lg ${bgColors[type]} min-w-[300px] max-w-md`}
        >
          {icons[type]}
          <p className={`flex-1 text-sm font-medium ${textColors[type]}`}>
            {message}
          </p>
          <button
            onClick={onClose}
            className="p-1 hover:bg-white/50 rounded-lg transition-colors"
          >
            <X className="w-4 h-4 text-gray-500" />
          </button>
        </div>
      </div>
    </>
  );
}
