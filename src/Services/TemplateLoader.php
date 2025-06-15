<?php

/**
 * Simple template loader for rendering HTML templates with variable substitution
 */
class TemplateLoader 
{
    private string $templateDir;
    
    public function __construct(string $templateDir = null) 
    {
        if ($templateDir === null) {
            // Default to public directory relative to project root
            $templateDir = __DIR__ . '/../../public';
        }
        $this->templateDir = rtrim($templateDir, '/');
    }
    
    /**
     * Load and render a template with variable substitution
     * 
     * @param string $templateName Name of the template file (without .html extension)
     * @param array $variables Associative array of variables to substitute
     * @return string Rendered template content
     * @throws Exception If template file is not found
     */
    public function render(string $templateName, array $variables = []): string 
    {
        $templateFile = $this->templateDir . '/' . $templateName . '.html';
        
        if (!file_exists($templateFile)) {
            throw new Exception("Template file not found: {$templateFile}");
        }
        
        $content = file_get_contents($templateFile);
        
        // Replace template variables in the format {{VARIABLE_NAME}}
        foreach ($variables as $key => $value) {
            $placeholder = '{{' . strtoupper($key) . '}}';
            $content = str_replace($placeholder, $value, $content);
        }
        
        return $content;
    }
    
    /**
     * Check if a template file exists
     * 
     * @param string $templateName Name of the template file (without .html extension)
     * @return bool True if template exists, false otherwise
     */
    public function exists(string $templateName): bool 
    {
        $templateFile = $this->templateDir . '/' . $templateName . '.html';
        return file_exists($templateFile);
    }
}
