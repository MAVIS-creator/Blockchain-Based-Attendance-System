using System;
using System.IO;
using System.Net;
using System.Text;
using System.Threading.Tasks;
using System.Windows;
using Newtonsoft.Json;

namespace HighQBiometricService
{
    public partial class MainWindow : Window
    {
        private HttpListener? _httpListener;
        private bool _isListening = false;
        private int _totalScanCount = 0;
        private object? _pendingScanEvent = null;

        public MainWindow()
        {
            InitializeComponent();
            StartHttpServer();
            LogMessage("High-Q Biometric Service initialized successfully.");
            LogMessage("Listening for PHP Web Application requests on http://localhost:8080/");
        }

        private async void StartHttpServer()
        {
            try
            {
                _httpListener = new HttpListener();
                _httpListener.Prefixes.Add("http://localhost:8080/");
                _httpListener.Start();
                _isListening = true;

                while (_isListening)
                {
                    var context = await _httpListener.GetContextAsync();
                    _ = ProcessRequestAsync(context);
                }
            }
            catch (Exception ex)
            {
                LogMessage($"[ERROR] HttpListener Error: {ex.Message}");
            }
        }

        private async Task ProcessRequestAsync(HttpListenerContext context)
        {
            var req = context.Request;
            var res = context.Response;

            // CORS headers for PHP browser calls
            res.Headers.Add("Access-Control-Allow-Origin", "*");
            res.Headers.Add("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
            res.Headers.Add("Access-Control-Allow-Headers", "Content-Type");

            if (req.HttpMethod == "OPTIONS")
            {
                res.StatusCode = 200;
                res.Close();
                return;
            }

            string rawUrl = req.Url?.AbsolutePath ?? "/";
            LogMessage($"[API Call] {req.HttpMethod} {rawUrl}");

            string responseJson = "";

            try
            {
                if (rawUrl == "/status")
                {
                    responseJson = JsonConvert.SerializeObject(new
                    {
                        status = "ok",
                        service = "High-Q Biometric Service",
                        connected = true,
                        reader = "DigitalPersona U.are.U 5160",
                        version = "1.0.0"
                    });
                }
                else if (rawUrl == "/enroll" && req.HttpMethod == "POST")
                {
                    Encoding encoding = req.ContentEncoding ?? Encoding.UTF8;
                    using var reader = new StreamReader(req.InputStream, encoding);
                    string body = await reader.ReadToEndAsync();
                    
                    _totalScanCount++;
                    Dispatcher.Invoke(() => TxtTotalScans.Text = $"{_totalScanCount} Scans");

                    // Generate enrollment template
                    string mockTemplate = "DP_SDK_TEMPLATE_" + Guid.NewGuid().ToString("N") + "_" + DateTime.UtcNow.Ticks;

                    responseJson = JsonConvert.SerializeObject(new
                    {
                        success = true,
                        message = "Fingerprint template captured successfully",
                        quality = "Excellent",
                        template = mockTemplate
                    });

                    LogMessage("[BIOMETRIC] Capture completed. Quality: Excellent.");
                }
                else if (rawUrl == "/terminal_scan_event")
                {
                    if (_pendingScanEvent != null)
                    {
                        responseJson = JsonConvert.SerializeObject(_pendingScanEvent);
                        _pendingScanEvent = null;
                    }
                    else
                    {
                        responseJson = JsonConvert.SerializeObject(new { matched = false });
                    }
                }
                else
                {
                    responseJson = JsonConvert.SerializeObject(new { success = false, message = "Endpoint not found" });
                }
            }
            catch (Exception ex)
            {
                responseJson = JsonConvert.SerializeObject(new { success = false, message = ex.Message });
            }

            byte[] buffer = Encoding.UTF8.GetBytes(responseJson);
            res.ContentType = "application/json";
            res.ContentLength64 = buffer.Length;
            await res.OutputStream.WriteAsync(buffer, 0, buffer.Length);
            res.Close();
        }

        private void LogMessage(string msg)
        {
            Dispatcher.Invoke(() =>
            {
                string timestamp = DateTime.Now.ToString("HH:mm:ss");
                TxtLog.AppendText($"[{timestamp}] {msg}\n");
                LogScrollViewer.ScrollToBottom();
            });
        }

        private void BtnMinimize_Click(object sender, RoutedEventArgs e)
        {
            WindowState = WindowState.Minimized;
        }

        private void BtnSimulate_Click(object sender, RoutedEventArgs e)
        {
            _totalScanCount++;
            TxtTotalScans.Text = $"{_totalScanCount} Scans";

            _pendingScanEvent = new
            {
                matched = true,
                admission_number = "HQ/2026/001",
                student_id = 1,
                timestamp = DateTime.Now.ToString("o")
            };

            LogMessage("[SIMULATED TOUCH] Fingerprint scanned on DigitalPersona 5160 reader. Triggered check-in.");
        }

        protected override void OnClosed(EventArgs e)
        {
            _isListening = false;
            _httpListener?.Stop();
            base.OnClosed(e);
        }
    }
}
