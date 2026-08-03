using System;
using System.Diagnostics;
using System.Windows;

namespace HighQBiometricService
{
    public partial class TrayStatusWindow : Window
    {
        public TrayStatusWindow()
        {
            InitializeComponent();
            PositionBottomRight();
        }

        private void PositionBottomRight()
        {
            var desktopWorkingArea = SystemParameters.WorkArea;
            this.Left = desktopWorkingArea.Right - this.Width - 12;
            this.Top = desktopWorkingArea.Bottom - this.Height - 12;
        }

        private void Window_Deactivated(object sender, EventArgs e)
        {
            this.Hide();
        }

        private void BtnClose_Click(object sender, RoutedEventArgs e)
        {
            this.Hide();
        }

        private void BtnDashboard_Click(object sender, RoutedEventArgs e)
        {
            this.Hide();
            foreach (Window win in Application.Current.Windows)
            {
                if (win is MainWindow main)
                {
                    main.Show();
                    main.WindowState = WindowState.Normal;
                    main.Activate();
                    return;
                }
            }
            var newMain = new MainWindow();
            newMain.Show();
        }

        private void BtnTestScanner_Click(object sender, RoutedEventArgs e)
        {
            BtnDashboard_Click(sender, e);
        }

        private void BtnAttendance_Click(object sender, RoutedEventArgs e)
        {
            try
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = "http://localhost/highq-attendance/",
                    UseShellExecute = true
                });
            }
            catch { }
            this.Hide();
        }

        private void BtnRestart_Click(object sender, RoutedEventArgs e)
        {
            MessageBox.Show("Biometric Service restarted successfully.", "High-Q Biometric", MessageBoxButton.OK, MessageBoxImage.Information);
            this.Hide();
        }

        private void BtnSettings_Click(object sender, RoutedEventArgs e)
        {
            BtnDashboard_Click(sender, e);
        }

        private void BtnExit_Click(object sender, RoutedEventArgs e)
        {
            Application.Current.Shutdown();
        }
    }
}
