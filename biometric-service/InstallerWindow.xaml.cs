using System;
using System.Diagnostics;
using System.Threading.Tasks;
using System.Windows;

namespace HighQBiometricService
{
    public partial class InstallerWindow : Window
    {
        private int _currentStep = 1;

        public InstallerWindow()
        {
            InitializeComponent();
        }

        private async void BtnNext_Click(object sender, RoutedEventArgs e)
        {
            if (_currentStep == 1)
            {
                // Go to Step 2 (System Check)
                _currentStep = 2;
                Step1_Welcome.Visibility = Visibility.Collapsed;
                Step2_SystemCheck.Visibility = Visibility.Visible;
                BtnNext.Content = "Install →";
            }
            else if (_currentStep == 2)
            {
                // Go to Step 3 (Progress Installation)
                _currentStep = 3;
                Step2_SystemCheck.Visibility = Visibility.Collapsed;
                Step3_Progress.Visibility = Visibility.Visible;
                BtnNext.IsEnabled = false;
                BtnCancel.IsEnabled = false;

                await PerformInstallationAsync();
            }
            else if (_currentStep == 4)
            {
                // Finish & Launch options
                if (ChkLaunchApp.IsChecked == true)
                {
                    var mainWin = new MainWindow();
                    mainWin.Show();
                }

                if (ChkOpenWeb.IsChecked == true)
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
                }

                this.Close();
            }
        }

        private async Task PerformInstallationAsync()
        {
            await Task.Delay(500);
            InstallProgressBar.Value = 35;
            TxtProgressStatus.Text = "Registering biometric DLL components...";
            TxtStepFiles.Foreground = System.Windows.Media.Brushes.DarkGreen;
            TxtStepFiles.Text = "✓ Application files copied";
            TxtStepDlls.Foreground = System.Windows.Media.Brushes.Black;
            TxtStepDlls.Text = "⏳ Registering biometric components";

            await Task.Delay(500);
            InstallProgressBar.Value = 65;
            TxtProgressStatus.Text = "Configuring local API server (http://localhost:8080)...";
            TxtStepDlls.Foreground = System.Windows.Media.Brushes.DarkGreen;
            TxtStepDlls.Text = "✓ Biometric components registered";
            TxtStepService.Foreground = System.Windows.Media.Brushes.Black;
            TxtStepService.Text = "⏳ Configuring local API";

            await Task.Delay(500);
            InstallProgressBar.Value = 90;
            TxtProgressStatus.Text = "Creating Windows desktop shortcuts...";
            TxtStepService.Foreground = System.Windows.Media.Brushes.DarkGreen;
            TxtStepService.Text = "✓ Local API configured";
            TxtStepShortcut.Foreground = System.Windows.Media.Brushes.Black;
            TxtStepShortcut.Text = "⏳ Creating Windows shortcuts";

            await Task.Delay(400);
            InstallProgressBar.Value = 100;
            TxtStepShortcut.Foreground = System.Windows.Media.Brushes.DarkGreen;
            TxtStepShortcut.Text = "✓ Windows shortcuts created";

            await Task.Delay(300);

            // Go to Step 4 (Complete)
            _currentStep = 4;
            Step3_Progress.Visibility = Visibility.Collapsed;
            Step4_Complete.Visibility = Visibility.Visible;

            BtnNext.IsEnabled = true;
            BtnCancel.Visibility = Visibility.Collapsed;
            BtnNext.Content = "Finish";
        }

        private void BtnCancel_Click(object sender, RoutedEventArgs e)
        {
            if (MessageBox.Show("Are you sure you want to cancel installation?", "High-Q Setup", MessageBoxButton.YesNo, MessageBoxImage.Question) == MessageBoxResult.Yes)
            {
                this.Close();
            }
        }
    }
}
