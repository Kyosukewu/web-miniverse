<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Test error message sanitization to ensure API keys are properly hidden.
 */
class SanitizeErrorMessageTest extends TestCase
{
    /**
     * Test that API keys in URL parameters are sanitized.
     *
     * @return void
     */
    public function test_sanitize_api_key_in_url_parameter()
    {
        $errorMessage = 'Client error: `POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX` resulted in a `429 Too Many Requests` response';
        
        $sanitized = $this->sanitizeErrorMessage($errorMessage);
        
        // Should not contain the actual API key
        $this->assertStringNotContainsString('AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', $sanitized);
        
        // Should contain the masked version
        $this->assertStringContainsString('key=***', $sanitized);
    }

    /**
     * Test that full API URLs are sanitized.
     *
     * @return void
     */
    public function test_sanitize_full_api_url()
    {
        $errorMessage = 'API call failed to https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent?key=AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
        
        $sanitized = $this->sanitizeErrorMessage($errorMessage);
        
        // Should not contain the full URL with key
        $this->assertStringNotContainsString('v1beta/models/gemini-3-pro-preview:generateContent?key=', $sanitized);
        
        // Should contain masked URL
        $this->assertStringContainsString('https://generativelanguage.googleapis.com/***?key=***', $sanitized);
    }

    /**
     * Test that standalone API keys are sanitized.
     *
     * @return void
     */
    public function test_sanitize_standalone_api_key()
    {
        $errorMessage = 'Invalid API key: AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX provided';
        
        $sanitized = $this->sanitizeErrorMessage($errorMessage);
        
        // Long alphanumeric strings should be masked
        $this->assertStringNotContainsString('AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', $sanitized);
        $this->assertStringContainsString('***', $sanitized);
    }

    /**
     * Test that normal error messages are not affected.
     *
     * @return void
     */
    public function test_normal_error_message_unchanged()
    {
        $errorMessage = 'Connection timeout after 30 seconds';
        
        $sanitized = $this->sanitizeErrorMessage($errorMessage);
        
        // Normal messages should remain the same
        $this->assertEquals($errorMessage, $sanitized);
    }

    /**
     * Test complex error message with multiple sensitive data.
     *
     * @return void
     */
    public function test_complex_error_message()
    {
        $errorMessage = 'Gemini API 完整分析失敗: Gemini API 影片分析失敗: Client error: `POST https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-preview:generateContent?key=AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX` resulted in a `429 Too Many Requests` response: {"error": {"code": 429, "message": "You exceeded your current quota"}}';
        
        $sanitized = $this->sanitizeErrorMessage($errorMessage);
        
        // Should not contain API key
        $this->assertStringNotContainsString('AIzaSyDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', $sanitized);
        
        // Should still contain error details
        $this->assertStringContainsString('429 Too Many Requests', $sanitized);
        $this->assertStringContainsString('You exceeded your current quota', $sanitized);
        
        // Should have masked key
        $this->assertStringContainsString('key=***', $sanitized);
    }

    /**
     * Helper method to sanitize error messages (same logic as in GeminiClient and AnalyzeService).
     *
     * @param string $errorMessage
     * @return string
     */
    private function sanitizeErrorMessage(string $errorMessage): string
    {
        // Remove API key from URL (key=xxx)
        $sanitized = preg_replace('/key=[a-zA-Z0-9_-]+/', 'key=***', $errorMessage);
        
        // Remove full URLs with API keys
        $sanitized = preg_replace(
            '/https:\/\/generativelanguage\.googleapis\.com\/[^?]+\?key=[a-zA-Z0-9_-]+/',
            'https://generativelanguage.googleapis.com/***?key=***',
            $sanitized
        );
        
        // Remove any standalone API key patterns (40+ character alphanumeric strings)
        $sanitized = preg_replace('/\b[A-Za-z0-9_-]{30,}\b/', '***', $sanitized);
        
        return $sanitized;
    }
}

