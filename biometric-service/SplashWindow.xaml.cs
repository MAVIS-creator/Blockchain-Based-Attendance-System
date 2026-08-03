using System;
using System.Threading.Tasks;
using System.Windows;

namespace HighQBiometricService
{
    public partial class SplashWindow : Window
    {
        public SplashWindow()
        {
            InitializeComponent();
            RunStartupSequenceAsync();
        }

        private async void RunStartupSequenceAsync()
        {
            await Task.Delay(400);
            SplashProgressBar.Value = 25;
            TxtStatus.Text = "Loading DigitalPersona SDK...";

            await Task.Delay(400);
            SplashProgressBar.Value = 50;
            TxtStatus.Text = "Detecting fingerprint reader...";

            await Task.Delay(400);
            SplashProgressBar.Value = 75;
            TxtStatus.Text = "Checking local API (http://localhost:8080)...";

            await Task.Delay(400);
            SplashProgressBar.Value = 100;
            TxtStatus.Text = "Ready";

            await Task.Delay(300);

            var mainWin = new MainWindow();
            mainWin.Show();
            this.Close();
        }
    }
}
