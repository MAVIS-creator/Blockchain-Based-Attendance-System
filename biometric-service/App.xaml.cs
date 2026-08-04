using System;
using System.Windows;
using System.Windows.Threading;

namespace HighQBiometricService
{
    public partial class App : Application
    {
        protected override void OnStartup(StartupEventArgs e)
        {
            base.OnStartup(e);

            // Catch unhandled UI thread exceptions
            this.DispatcherUnhandledException += App_DispatcherUnhandledException;

            // Catch unhandled background thread exceptions
            AppDomain.CurrentDomain.UnhandledException += CurrentDomain_UnhandledException;
        }

        private void App_DispatcherUnhandledException(object sender, DispatcherUnhandledExceptionEventArgs e)
        {
            MessageBox.Show(
                $"An error occurred:\n\n{e.Exception.Message}\n\n{e.Exception.StackTrace}",
                "High-Q Biometric Service Error",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
            e.Handled = true;
        }

        private void CurrentDomain_UnhandledException(object sender, UnhandledExceptionEventArgs e)
        {
            if (e.ExceptionObject is Exception ex)
            {
                MessageBox.Show(
                    $"A critical error occurred:\n\n{ex.Message}",
                    "High-Q Biometric Service Error",
                    MessageBoxButton.OK,
                    MessageBoxImage.Error);
            }
        }
    }
}
